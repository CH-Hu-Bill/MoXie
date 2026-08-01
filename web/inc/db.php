<?php
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

    // ==================== 全局设置 (Settings) ====================
    /** @return array 设置数组，包含 last_task_id_{classId}, default_volume, default_interval, default_repeat, weekend_lottery_{classId}, weekend_week_{classId} */
    public static function getSettings() { return self::read('settings.json'); }
    /** @param array $settings 设置数组 */
    public static function saveSettings($settings) { return self::write('settings.json', $settings); }

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
