<?php

date_default_timezone_set('Asia/Shanghai');
require_once __DIR__ . '/input.php';
/**
 * ============================================================
 * 数据库访问层 (Database Access Layer)
 * ============================================================
 * 
 * 使用 JSON 文件存储数据，按班级隔离为独立子目录。
 * 数据目录: /data/
 * 
 * 目录结构:
 *   data/
 *   ├── classes.json              — 全局班级注册表
 *   ├── settings.json             — 全局设置 (键名: {功能}_{classId} 或全局键)
 *   ├── app_data.json             — APP 用户/token (全局)
 *   ├── app_versions.json         — APP 版本发布日志 (全局)
 *   ├── exports.json + exports/   — 临时导出文件与 token (全局)
 *   ├── ratelimit.json            — 限流计数 (全局)
 *   └── classes/
 *       └── {classId}/
 *           ├── words.json            — 单词数组
 *           ├── tasks.json            — 任务 (关联数组, key=taskId)
 *           ├── history.json          — 班级史记
 *           ├── gallery.json          — 图集元数据
 *           ├── personal_history_{uid}.json — 个人列传
 *           └── uploads/              — 上传图片
 * 
 * 写入采用原子操作 (临时文件 + rename)，防止并发写入导致数据损坏。
 * 班级数据通过 update() 在 .lock 文件锁内读取-修改-写回，避免并发覆盖。
 * 所有读取无锁；若文件不存在返回空数组，由调用方处理默认值。
 * 
 * 维护注意:
 *   - 班级相关数据用 classDataFile() / updateClassData() 等方法，自动归入子目录
 *   - 全局数据用 read() / write() / update()，留在 data/ 根
 *   - 不要直接操作文件路径，始终通过本类访问
 * ============================================================
 */
class Database {
    /** @var string 数据文件存放目录 (含末尾分隔符) */
    private static $dataPath = __DIR__ . '/../data/';

    /** @var string 班级数据子目录名 (data/classes/) */
    private static $classesDir = 'classes';

    /**
     * 获取全局数据文件的完整路径
     * @param string $filename 文件名 (如 'classes.json')
     * @return string 完整路径
     */
    public static function getFilePath($filename) {
        self::validateFilename($filename);
        return self::$dataPath . $filename;
    }

    /**
     * 获取班级子目录的完整路径
     * @param string $classId 班级ID
     * @return string 完整路径 (不含末尾分隔符)
     */
    public static function getClassDir($classId) {
        $classId = self::validateClassId($classId);
        return self::$dataPath . self::$classesDir . '/' . $classId;
    }

    /**
     * 构造班级数据文件名 (相对 data/ 的路径，供 read/write/update 使用)
     * @param string $classId 班级ID
     * @param string $key 数据键 (如 'words', 'tasks', 'history', 'gallery')
     * @return string 相对路径 (如 'classes/{classId}/words.json')
     */
    public static function classDataFile($classId, $key) {
        $classId = self::validateClassId($classId);
        $key = (string)$key;
        if (!preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]*\z/D', $key)) {
            throw new InvalidArgumentException('Invalid class data key');
        }
        return self::$classesDir . '/' . $classId . '/' . $key . '.json';
    }

    /** @throws InvalidArgumentException */
    private static function validateFilename($filename) {
        // 允许 data/ 根的全局文件，也允许 classes/{cid}/ 子目录路径
        if (!is_string($filename)) {
            throw new InvalidArgumentException('Invalid JSON filename');
        }
        // 形如 classes/{id}/key.json 的子目录路径
        if (preg_match('#\Aclasses/([A-Za-z0-9][A-Za-z0-9_-]*)/([A-Za-z0-9_][A-Za-z0-9_.-]*)\.json\z#D', $filename, $m)) {
            return;
        }
        // 形如 name.json 的根文件
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\.json\z/D', $filename) || basename($filename) !== $filename) {
            throw new InvalidArgumentException('Invalid JSON filename');
        }
    }

    /** @throws InvalidArgumentException */
    private static function validateId($id, $type) {
        if (!is_string($id) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D', $id)) {
            throw new InvalidArgumentException('Invalid ' . $type . ' ID');
        }
        return $id;
    }

    /** @return string */
    public static function validateClassId($classId) { return self::validateId($classId, 'class'); }
    /** @return string */
    public static function validateUserId($userId) { return self::validateId($userId, 'user'); }

    /**
     * 读取 JSON 数据文件
     * 文件不存在时返回空数组；读取失败或 JSON 损坏时抛出异常。
     * @param string $filename 文件名
     * @return array 解析后的数据
     */
    public static function read($filename) {
        $file = self::getFilePath($filename);
        if (!file_exists($file)) {
            return [];
        }
        $content = file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException('Unable to read JSON file: ' . $filename);
        }
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new RuntimeException('Invalid JSON file: ' . $filename);
        }
        return $data;
    }

    /**
     * 原子写入 JSON 数据文件
     * 先写入临时文件 (带排他锁)，再 rename 覆盖目标文件。
     * 保证写入过程中不会出现数据损坏或并发覆盖。
     * @param string $filename 文件名
     * @param array  $data     待写入的数据
     * @return bool 写入是否成功
     */
    public static function write($filename, $data) {
        $file = self::getFilePath($filename);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) return false;
        // 原子写入: 临时文件 + 排他锁 + rename
        $tmp = $file . '.' . uniqid('', true) . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
        if (!rename($tmp, $file)) { @unlink($tmp); return false; }
        return true;
    }

    /**
     * 在文件锁内读取、修改并写回，避免并发请求互相覆盖。
     * 回调返回更新后的数组；返回 null 表示不写入。
     */
    public static function update($filename, callable $callback) {
        $file = self::getFilePath($filename);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $lock = fopen($file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if ($lock !== false) fclose($lock);
            return false;
        }
        try {
            $data = self::read($filename);
            $updated = $callback($data);
            if ($updated === null) return $data;
            if (!is_array($updated)) {
                throw new RuntimeException('Update callback must return an array or null');
            }
            if (!self::write($filename, $updated)) {
                throw new RuntimeException('Unable to write JSON file: ' . $filename);
            }
            return $updated;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** 删除数据文件；不存在时同样视为成功。 */
    public static function delete($filename) {
        $file = self::getFilePath($filename);
        return !file_exists($file) || unlink($file);
    }

    /**
     * 获取上传文件目录路径
     * @param string|null $classId 班级ID（传入则返回 data/classes/{classId}/uploads/）
     * @return string 目录绝对路径
     */
    public static function getUploadsDirectory($classId = null) {
        if ($classId === null) {
            return self::$dataPath . 'uploads';
        }
        return self::getClassDir($classId) . '/uploads';
    }

    /**
     * 获取导出文件目录路径
     * @return string 目录绝对路径
     */
    public static function getExportsDirectory() {
        return self::$dataPath . 'exports';
    }

    // ==================== 班级数据通用方法 (classes/{classId}/) ====================
    /**
     * 读取班级数据文件
     * @param string $classId 班级ID
     * @param string $key 数据键 (words / tasks / history / gallery / personal_history_{uid} 等)
     * @return array
     */
    public static function getClassData($classId, $key) {
        return self::read(self::classDataFile($classId, $key));
    }

    /**
     * 原子写入班级数据文件
     * @param string $classId 班级ID
     * @param string $key 数据键
     * @param array $data 待写入数据
     * @return bool
     */
    public static function saveClassData($classId, $key, $data) {
        return self::write(self::classDataFile($classId, $key), $data);
    }

    /**
     * 在文件锁内读取-修改-写回班级数据
     * @param string $classId 班级ID
     * @param string $key 数据键
     * @param callable $callback 回调返回更新后数组；返回 null 表示不写入
     * @return array|false 更新后的数据，或 false 表示加锁失败
     */
    public static function updateClassData($classId, $key, callable $callback) {
        return self::update(self::classDataFile($classId, $key), $callback);
    }

    /** 删除班级数据文件；不存在时同样视为成功。 */
    public static function deleteClassData($classId, $key) {
        return self::delete(self::classDataFile($classId, $key));
    }

    // ==================== 班级注册表 (Classes) ====================
    /** @return array 班级列表，key=classId, value={id,name,created_at,password_hash,auth_version} */
    public static function getClasses() { return self::read('classes.json'); }
    /** @param array $classes 班级列表 */
    public static function saveClasses($classes) { return self::write('classes.json', $classes); }

    // ==================== 单词库 (Words) ====================
    /** @param string $classId 班级ID */
    /** @return array 单词数组 [{id,word,meaning,pos,created_at}, ...] */
    public static function getWords($classId) { return self::getClassData($classId, 'words'); }
    /** @param string $classId 班级ID */
    /** @param array $words 单词数组 */
    public static function saveWords($classId, $words) { return self::saveClassData($classId, 'words', $words); }

    // ==================== 任务 (Tasks) ====================
    /** @param string $classId 班级ID */
    /** @return array 任务关联数组，key=taskId, value={id,date,label,word_ids,status,created_at} */
    public static function getTasks($classId) { return self::getClassData($classId, 'tasks'); }
    /** @param string $classId 班级ID */
    /** @param array $tasks 任务关联数组 */
    public static function saveTasks($classId, $tasks) { return self::saveClassData($classId, 'tasks', $tasks); }

    // ==================== 用户数据存储 (Users) ====================
    /**
     * 获取用户数据目录
     * @return string 目录绝对路径
     */
    public static function getUsersDir() {
        $dir = self::$dataPath . 'users';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        return $dir;
    }

    /**
     * 读取单个用户数据
     * @param string $uid 用户ID
     * @return array|null 用户数据数组，不存在返回 null
     */
    public static function getUser($uid) {
        self::validateUserId($uid);
        $file = self::getUsersDir() . '/' . $uid . '.json';
        if (!file_exists($file)) return null;
        $content = @file_get_contents($file);
        if ($content === false) return null;
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 写入单个用户数据（原子写入）
     * @param string $uid 用户ID
     * @param array $data 用户数据
     * @return bool 是否成功
     */
    public static function saveUser($uid, $data) {
        self::validateUserId($uid);
        $file = self::getUsersDir() . '/' . $uid . '.json';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $tmp = $file . '.' . uniqid('', true) . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
        if (!rename($tmp, $file)) { @unlink($tmp); return false; }
        return true;
    }

    /**
     * 在文件锁内读取-修改-写回用户数据
     * @param string $uid 用户ID
     * @param callable $callback 回调返回更新后数组；返回 null 表示不写入
     * @return array|false 更新后的数据，或 false 表示加锁失败
     */
    public static function updateUser($uid, callable $callback) {
        $file = self::getUsersDir() . '/' . self::validateUserId($uid) . '.json';
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $lock = fopen($file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if ($lock !== false) fclose($lock);
            return false;
        }
        try {
            $data = self::getUser($uid) ?: [];
            $updated = $callback($data);
            if ($updated === null) return $data;
            if (!is_array($updated)) throw new RuntimeException('Update callback must return an array or null');
            if (!self::saveUser($uid, $updated)) throw new RuntimeException('Unable to write user file');
            return $updated;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * 删除用户数据文件
     * @param string $uid 用户ID
     * @return bool 是否成功
     */
    public static function deleteUser($uid) {
        $file = self::getUsersDir() . '/' . self::validateUserId($uid) . '.json';
        return !file_exists($file) || unlink($file);
    }

    /**
     * 获取所有用户ID列表
     * @return array 用户ID数组
     */
    public static function getAllUserIds() {
        $dir = self::getUsersDir();
        $ids = [];
        $items = @scandir($dir);
        if ($items === false) return $ids;
        foreach ($items as $item) {
            if (preg_match('/\A([A-Za-z0-9][A-Za-z0-9_-]*)\.json\z/D', $item, $m)) {
                $ids[] = $m[1];
            }
        }
        return $ids;
    }

    /**
     * 获取所有用户数据（key=uid, value=user data）
     * @return array 用户数据数组
     */
    public static function getAllUsers() {
        $users = [];
        foreach (self::getAllUserIds() as $uid) {
            $user = self::getUser($uid);
            if ($user !== null) $users[$uid] = $user;
        }
        return $users;
    }

    /**
     * 读取令牌数据
     * @return array ['tokens' => [...], 'next_uid' => int]
     */
    public static function getTokens() {
        $file = self::getUsersDir() . '/tokens.json';
        if (!file_exists($file)) return ['tokens' => [], 'next_uid' => 1];
        $content = @file_get_contents($file);
        if ($content === false) return ['tokens' => [], 'next_uid' => 1];
        $data = json_decode($content, true);
        return is_array($data) ? $data : ['tokens' => [], 'next_uid' => 1];
    }

    /**
     * 写入令牌数据（原子写入）
     * @param array $data 令牌数据
     * @return bool 是否成功
     */
    public static function saveTokens($data) {
        $file = self::getUsersDir() . '/tokens.json';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $tmp = $file . '.' . uniqid('', true) . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
        if (!rename($tmp, $file)) { @unlink($tmp); return false; }
        return true;
    }

    /**
     * 在文件锁内读取-修改-写回令牌数据
     * @param callable $callback 回调返回更新后数组；返回 null 表示不写入
     * @return array|false 更新后的数据，或 false 表示加锁失败
     */
    public static function updateTokens(callable $callback) {
        $file = self::getUsersDir() . '/tokens.json';
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $lock = fopen($file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if ($lock !== false) fclose($lock);
            return false;
        }
        try {
            $data = self::getTokens();
            $updated = $callback($data);
            if ($updated === null) return $data;
            if (!is_array($updated)) throw new RuntimeException('Update callback must return an array or null');
            if (!self::saveTokens($updated)) throw new RuntimeException('Unable to write tokens file');
            return $updated;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * 从旧版 app_data.json 迁移到新版 users/ 目录结构
     * 迁移后重命名旧文件为 app_data.json.bak
     */
    public static function migrateAppData() {
        $oldFile = self::$dataPath . 'app_data.json';
        if (!file_exists($oldFile)) return;
        $content = @file_get_contents($oldFile);
        if ($content === false) return;
        $data = json_decode($content, true);
        if (!is_array($data)) return;
        $usersDir = self::getUsersDir();
        $changed = false;
        if (isset($data['users']) && is_array($data['users'])) {
            foreach ($data['users'] as $uid => $userData) {
                $userFile = $usersDir . '/' . $uid . '.json';
                if (!file_exists($userFile)) {
                    @file_put_contents($userFile, json_encode($userData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
                    $changed = true;
                }
            }
        }
        $tokensFile = $usersDir . '/tokens.json';
        if (!file_exists($tokensFile)) {
            $tokensData = [];
            if (isset($data['tokens'])) $tokensData['tokens'] = $data['tokens'];
            if (isset($data['next_uid'])) $tokensData['next_uid'] = $data['next_uid'];
            @file_put_contents($tokensFile, json_encode($tokensData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
            $changed = true;
        }
        if ($changed) {
            @rename($oldFile, $oldFile . '.bak');
        }
    }

    // ==================== 全局设置 (Settings) ====================
    /** @return array 设置数组，包含 last_task_id_{classId}, default_volume, default_interval, default_repeat, weekend_lottery_{classId}, weekend_week_{classId} */
    public static function getSettings() { return self::read('settings.json'); }
    /** @param array $settings 设置数组 */
    public static function saveSettings($settings) { return self::write('settings.json', $settings); }

    // ==================== 公告 (Announcements) ====================
    public static function getAnnouncements() {
        $data = self::read('announcements.json');
        return $data['announcements'] ?? [];
    }
    public static function saveAnnouncements($announcements) {
        return self::write('announcements.json', ['announcements' => $announcements]);
    }

    // ==================== 班级级联删除 ====================
    /**
     * 删除班级的整个数据目录 (含所有 json、uploads 子目录)。
     * 仅清空班级数据，不修改 classes.json 注册表（由调用方处理）。
     * @param string $classId 班级ID
     */
    public static function deleteClassDataDir($classId) {
        $dir = self::getClassDir($classId);
        if (!is_dir($dir)) return;
        $items = @scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                foreach (scandir($path) ?: [] as $sub) {
                    if ($sub === '.' || $sub === '..') continue;
                    @unlink($path . DIRECTORY_SEPARATOR . $sub);
                }
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // ==================== 上传图片管理 ====================
    /** @var int 单张图片最大字节数 (12MB) */
    const UPLOAD_MAX_BYTES = 12582912;
    /** @var int 图片最大像素数 (2500 万) */
    const UPLOAD_MAX_PIXELS = 25000000;
    /** @var int 缩放后最长边像素 */
    const UPLOAD_MAX_EDGE = 1600;
    /** @var int GIF 单文件最大字节数 (16MB) */
    const GIF_MAX_BYTES = 16777216;
    /** @var int GIF 最大帧数 */
    const GIF_MAX_FRAMES = 300;
    /** @var int GIF 单帧像素 × 帧数 上限 (8000 万) */
    const GIF_MAX_PIXEL_FRAMES = 80000000;
    /** @var int GIF 单边最大像素（逻辑屏/帧，防超大单帧） */
    const GIF_MAX_EDGE = 8000;
    /** @var int MP4 单文件最大字节数 (15MB) */
    const MP4_MAX_BYTES = 15728640;
    /** @var int MP4 最大时长（秒） */
    const MP4_MAX_SECONDS = 30;

    /**
     * 安全保存上传图片：校验 → GD 解码 → 等比缩放 → 保存 → 落盘校验。
     * 所有图片上传入口 (upload.php / gallery.php / app_api.php) 统一调用此方法，
     * 避免逻辑重复导致路径/处理不一致。
     *
     * @param string $classId 班级ID
     * @param string $tmpPath 临时文件路径 ($_FILES['x']['tmp_name'])
     * @return string 生成的文件名 (32位hex + 扩展名)
     * @throws RuntimeException 校验或保存失败时抛出 (含可读消息)
     */
    public static function saveUploadedImage($classId, $tmpPath) {
        $classId = self::validateClassId($classId);

        // MP4 视频：先于 getimagesize 判断（MP4 不是图像，getimagesize 会失败）
        if (self::isMp4File($tmpPath)) {
            return self::saveMp4Image($classId, $tmpPath);
        }

        $info = @getimagesize($tmpPath);
        if (!$info) {
            throw new RuntimeException('无法识别该文件：仅支持 JPEG、PNG、WebP、GIF 图片或 MP4 视频');
        }

        // GIF 动图：走独立校验 + 原样存储分支（GD 只读首帧会丢动画）
        if ($info[2] === IMAGETYPE_GIF) {
            return self::saveGifImage($classId, $tmpPath);
        }

        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('服务器未启用 GD，无法处理图片');
        }
        $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if (!isset($allowed[$info[2]])) {
            throw new RuntimeException('仅支持 JPEG、PNG、WebP 或 GIF 图片');
        }
        $width = (int)$info[0];
        $height = (int)$info[1];
        if ($width < 1 || $height < 1 || $width > intdiv(self::UPLOAD_MAX_PIXELS, $height)) {
            throw new RuntimeException('图片像素过大，最多 2500 万像素');
        }

        $loaders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
        if (!function_exists($loaders[$info[2]])) {
            throw new RuntimeException('服务器 GD 不支持该图片格式');
        }
        $source = @$loaders[$info[2]]($tmpPath);
        if (!$source) throw new RuntimeException('图片内容损坏或无法解码');

        $scale = min(1, self::UPLOAD_MAX_EDGE / max($width, $height));
        $tw = max(1, (int)round($width * $scale));
        $th = max(1, (int)round($height * $scale));
        $target = imagecreatetruecolor($tw, $th);
        if (!$target) { imagedestroy($source); throw new RuntimeException('图片处理失败'); }
        if ($info[2] !== IMAGETYPE_JPEG) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        }
        if (!imagecopyresampled($target, $source, 0, 0, 0, 0, $tw, $th, $width, $height)) {
            imagedestroy($source); imagedestroy($target);
            throw new RuntimeException('图片缩放失败');
        }

        $dir = self::getUploadsDirectory($classId);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            imagedestroy($source); imagedestroy($target);
            throw new RuntimeException('上传目录不可用');
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$info[2]];
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        $writers = [
            IMAGETYPE_JPEG => function($img, $p) { return imagejpeg($img, $p, 82); },
            IMAGETYPE_PNG  => function($img, $p) { return imagepng($img, $p, 7); },
            IMAGETYPE_WEBP => function($img, $p) { return imagewebp($img, $p, 82); },
        ];
        $saved = $writers[$info[2]]($target, $path);
        imagedestroy($source);
        imagedestroy($target);
        if (!$saved) { @unlink($path); throw new RuntimeException('图片保存失败'); }
        @chmod($path, 0640);

        // 落盘校验：确认文件真实存在且可读，避免「保存成功」但实际丢失
        if (!is_file($path) || !is_readable($path) || filesize($path) === 0) {
            @unlink($path);
            throw new RuntimeException('图片落盘校验失败');
        }
        return $filename;
    }

    /**
     * 安全保存 GIF 动图：GIF 炸弹校验 → 原样复制 → 落盘校验。
     * GIF 走独立路径（不经 GD 重采样），以保证多帧动画完整保留。
     *
     * @param string $classId 班级ID
     * @param string $tmpPath 临时文件路径
     * @return string 生成的文件名 (32位hex.gif)
     * @throws RuntimeException 校验或保存失败时抛出 (含可读消息)
     */
    public static function saveGifImage($classId, $tmpPath) {
        $classId = self::validateClassId($classId);

        // 文件大小限制
        $size = @filesize($tmpPath);
        if ($size === false || $size <= 0) throw new RuntimeException('无法读取上传文件');
        if ($size > self::GIF_MAX_BYTES) throw new RuntimeException('GIF 动图最大 16MB');

        // GIF 炸弹校验（帧数 / 像素×帧 / 结构合法性）
        require_once __DIR__ . '/gif_guard.php';
        $guard = GifGuard::validate($tmpPath, self::GIF_MAX_FRAMES, self::GIF_MAX_PIXEL_FRAMES);

        $dir = self::getUploadsDirectory($classId);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('上传目录不可用');
        }
        $filename = bin2hex(random_bytes(16)) . '.gif';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        if (!@copy($tmpPath, $path)) {
            @unlink($path);
            throw new RuntimeException('GIF 保存失败');
        }
        @chmod($path, 0640);

        // 落盘校验
        if (!is_file($path) || !is_readable($path) || filesize($path) === 0) {
            @unlink($path);
            throw new RuntimeException('GIF 落盘校验失败');
        }
        return $filename;
    }

    /**
     * 判断文件是否为 MP4（读前 12 字节：box size + 'ftyp'）。
     * 独立于 getimagesize（MP4 不是图像，getimagesize 会失败）。
     */
    public static function isMp4File($tmpPath) {
        $fh = @fopen($tmpPath, 'rb');
        if (!$fh) return false;
        $head = fread($fh, 12);
        fclose($fh);
        if (strlen($head) < 12) return false;
        $boxType = substr($head, 4, 4);
        return $boxType === 'ftyp';
    }

    /**
     * 安全保存 MP4 视频：mp4_guard 校验（时长/大小/结构）→ 原样复制 → 落盘校验。
     * 服务器无 ffmpeg，不转码，压缩由用户端导出时完成。
     *
     * @param string $classId 班级ID
     * @param string $tmpPath 临时文件路径
     * @return string 生成的文件名 (32位hex.mp4)
     * @throws RuntimeException 校验或保存失败时抛出 (含可读消息)
     */
    public static function saveMp4Image($classId, $tmpPath) {
        $classId = self::validateClassId($classId);

        require_once __DIR__ . '/mp4_guard.php';
        $guard = Mp4Guard::validate($tmpPath, self::MP4_MAX_SECONDS, self::MP4_MAX_BYTES);

        $dir = self::getUploadsDirectory($classId);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('上传目录不可用');
        }
        $filename = bin2hex(random_bytes(16)) . '.mp4';
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        if (!@copy($tmpPath, $path)) {
            @unlink($path);
            throw new RuntimeException('视频保存失败');
        }
        @chmod($path, 0640);

        // 落盘校验
        if (!is_file($path) || !is_readable($path) || filesize($path) === 0) {
            @unlink($path);
            throw new RuntimeException('视频落盘校验失败');
        }
        return $filename;
    }

    /**
     * 获取班级上传图片的绝对路径 (带文件名校验，防路径穿越)。
     * @param string $classId 班级ID
     * @param string $filename 文件名 (32位hex + 扩展名)
     * @return string|null 绝对路径；文件名非法或文件不存在返回 null
     */
    public static function getUploadedImagePath($classId, $filename) {
        if (!preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp|gif|mp4)\z/D', $filename)) return null;
        $path = self::getUploadsDirectory($classId) . DIRECTORY_SEPARATOR . $filename;
        return is_file($path) ? $path : null;
    }

    /**
     * 删除班级上传图片 (带文件名校验)。
     * @param string $classId 班级ID
     * @param string $filename 文件名
     * @return bool 是否删除成功 (文件不存在也返回 true)
     */
    public static function deleteUploadedImage($classId, $filename) {
        $path = self::getUploadedImagePath($classId, $filename);
        if ($path === null) return true;
        return @unlink($path);
    }

    // ==================== 工具方法 ====================
    /**
     * 自动取消过期任务
     * 将 date < 今天的 pending 任务标记为 cancelled，有变更时自动保存。
     * @param string $classId 班级ID
     * @return array 更新后的任务数组
     */
    public static function autoCancelExpiredTasks($classId) {
        self::validateClassId($classId);
        $today = date('Y-m-d');
        $currentWeek = date('o-W');
        $tasks = self::getTasks($classId);
        $changed = false;
        foreach ($tasks as $tid => $task) {
            $taskDate = $task['date'] ?? '';
            $taskTimestamp = strtotime($taskDate . ' 12:00:00');
            $isCurrentWeekend = $taskTimestamp !== false
                && date('o-W', $taskTimestamp) === $currentWeek
                && (int)date('N', $taskTimestamp) >= 6;
            $isCurrentWeekendTask = ($task['weekend_week'] ?? '') === $currentWeek
                || (($task['label'] ?? '') === '周末大礼包' && $isCurrentWeekend);
            if (($task['status'] ?? '') === 'pending' && $taskDate < $today && !$isCurrentWeekendTask) {
                $tasks[$tid]['status'] = 'cancelled';
                $changed = true;
            }
        }
        if ($changed) {
            self::saveTasks($classId, $tasks);
        }
        return $tasks;
    }
}
