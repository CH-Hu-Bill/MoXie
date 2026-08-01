<?php
/**
 * ============================================================
 * 数据库访问层 (Database Access Layer)
 * ============================================================
 * 
 * 使用 JSON 文件存储数据，每个班级独立文件。
 * 数据目录: /data/
 * 
 * 文件映射:
 *   classes.json           — 班级列表
 *   words_{classId}.json   — 各班级单词数据 (数组)
 *   tasks_{classId}.json   — 各班级任务数据 (关联数组，key=taskId)
 *   settings.json          — 全局设置 (含各班级lastTaskId、周末抽奖等)
 * 
 * 写入采用原子操作 (临时文件 + rename)，防止并发写入导致数据损坏。
 * 所有读取无锁；若文件不存在返回空数组，由调用方处理默认值。
 * 
 * 维护注意:
 *   - 新增数据文件时在此处添加对应的 getXxx() / saveXxx() 方法
 *   - 不要直接操作文件路径，始终通过本类访问
 *   - settings.json 中的键名格式: {功能}_{classId} 或全局键
 * ============================================================
 */
class Database {
    /** @var string 数据文件存放目录 */
    private static $dataPath = __DIR__ . '/../data/';

    /**
     * 获取数据文件的完整路径
     * @param string $filename 文件名 (如 'classes.json')
     * @return string 完整路径
     */
    public static function getFilePath($filename) {
        self::validateFilename($filename);
        return self::$dataPath . $filename;
    }

    /** @throws InvalidArgumentException */
    private static function validateFilename($filename) {
        if (!is_string($filename) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\.json\z/D', $filename) || basename($filename) !== $filename) {
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
     * @param string $classId 班级ID（可选，传入则返回该班级子目录）
     * @return string 目录绝对路径
     */
    public static function getUploadsDirectory($classId = null) {
        $dir = dirname(self::$dataPath) . '/data/uploads';
        if ($classId !== null) {
            self::validateClassId($classId);
            $dir .= '/' . $classId;
        }
        return $dir;
    }

    /**
     * 获取导出文件目录路径
     * @return string 目录绝对路径
     */
    public static function getExportsDirectory() {
        return dirname(self::$dataPath) . '/data/exports';
    }

    // ==================== 班级 (Classes) ====================
    /** @return array 班级列表，key=classId, value={id,name,created_at} */
    public static function getClasses() { return self::read('classes.json'); }
    /** @param array $classes 班级列表 */
    public static function saveClasses($classes) { return self::write('classes.json', $classes); }

    // ==================== 单词库 (Words) ====================
    /** @param string $classId 班级ID */
    /** @return array 单词数组 [{id,word,meaning,pos,created_at}, ...] */
    public static function getWords($classId) { return self::read('words_' . self::validateClassId($classId) . '.json'); }
    /** @param string $classId 班级ID */
    /** @param array $words 单词数组 */
    public static function saveWords($classId, $words) { return self::write('words_' . self::validateClassId($classId) . '.json', $words); }

    // ==================== 任务 (Tasks) ====================
    /** @param string $classId 班级ID */
    /** @return array 任务关联数组，key=taskId, value={id,date,label,word_ids,status,created_at} */
    public static function getTasks($classId) { return self::read('tasks_' . self::validateClassId($classId) . '.json'); }
    /** @param string $classId 班级ID */
    /** @param array $tasks 任务关联数组 */
    public static function saveTasks($classId, $tasks) { return self::write('tasks_' . self::validateClassId($classId) . '.json', $tasks); }

    // ==================== 全局设置 (Settings) ====================
    /** @return array 设置数组，包含 last_task_id_{classId}, default_volume, default_interval, default_repeat, weekend_lottery_{classId}, weekend_week_{classId} */
    public static function getSettings() { return self::read('settings.json'); }
    /** @param array $settings 设置数组 */
    public static function saveSettings($settings) { return self::write('settings.json', $settings); }

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
