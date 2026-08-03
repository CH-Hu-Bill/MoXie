export interface VlogEntry {
  id: number;
  date: string;
  title: string;
  content: string;
  author: string;
  author_id: number;
  is_owner: boolean;
}

export interface VlogListResponse {
  success: boolean;
  entries: VlogEntry[];
  has_more: boolean;
}

export interface SaveVlogResponse {
  success: boolean;
  message?: string;
  entry_id?: number;
}

export interface VlogCalendarResponse {
  success: boolean;
  dates: string[];
}