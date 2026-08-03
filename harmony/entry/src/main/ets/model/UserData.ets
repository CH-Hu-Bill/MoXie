export interface UserData {
  id: number;
  username: string;
  token: string;
}

export interface LoginResponse {
  success: boolean;
  message?: string;
  token?: string;
  user_id?: number;
  username?: string;
}

export interface ClassInfo {
  class_id: number;
  class_name: string;
  subject: string;
  level: string;
  auth_version: number;
}

export interface ClassesResponse {
  success: boolean;
  classes: ClassInfo[];
  message?: string;
}

export interface BindClassResponse {
  success: boolean;
  message?: string;
  auth_version?: number;
  class_id?: number;
}