<?php
/**
 * 任务相关的纯函数。
 */

/**
 * 从所有任务中移除某个知识库条目；移除后为空的任务直接删除。
 * 用于删除单词/句子/作文后，避免留下“没有内容的任务”。
 *
 * @param array $tasks 任务 map (key=taskId)
 * @param string $wordId 被删除的知识库条目 id
 * @return array{tasks: array, removed_ids: string[]}
 */
function tasksRemoveWordAndPrune(array $tasks, string $wordId): array {
    $removed = [];
    foreach ($tasks as $tid => $task) {
        if (!is_array($task)) continue;
        $ids = array_values(array_filter($task['word_ids'] ?? [], function($id) use ($wordId) {
            return (string)$id !== $wordId;
        }));
        if (count($ids) === 0) {
            $removed[] = (string)($task['id'] ?? $tid);
            unset($tasks[$tid]);
        } else {
            $tasks[$tid]['word_ids'] = $ids;
        }
    }
    return ['tasks' => $tasks, 'removed_ids' => $removed];
}
