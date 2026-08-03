export interface VersionCheckResponse {
  success: boolean;
  latest_version?: string;
  update_url?: string;
  release_notes?: string;
  force_update?: boolean;
}

export interface SearchApiResponse {
  success: boolean;
  results?: SearchResultItem[];
}

export interface SearchResultItem {
  id: string;
  type: string;
  title: string;
  subtitle: string;
}