export interface WordItem {
  id: number;
  word: string;
  meaning: string;
  part_of_speech: string;
  example: string;
  is_favorite: boolean;
  is_wrong: boolean;
  task_date?: string;
  completed?: boolean;
}

export interface WordListResponse {
  success: boolean;
  words: WordItem[];
  total: number;
  has_more: boolean;
}

export interface AddWordResponse {
  success: boolean;
  message?: string;
  word_id?: number;
}

export interface ToggleFavoriteResponse {
  success: boolean;
  is_favorite: boolean;
}

export interface ToggleWrongResponse {
  success: boolean;
  is_wrong: boolean;
}