export interface TaskItem {
  id: number;
  title: string;
  description: string;
  date: string;
  word_count: number;
  status: string;
  completed_count: number;
}

export interface TaskDetail {
  task: TaskItem;
  words: TaskWordItem[];
}

export interface TaskWordItem {
  word_id: number;
  word: string;
  meaning: string;
  completed: boolean;
}

export interface TaskListResponse {
  success: boolean;
  tasks: TaskItem[];
  has_more: boolean;
}

export interface TaskDetailResponse {
  success: boolean;
  task: TaskItem;
  words: TaskWordItem[];
}

export interface CompleteTaskResponse {
  success: boolean;
  message?: string;
}