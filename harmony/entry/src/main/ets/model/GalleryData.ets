export interface GalleryItem {
  id: number;
  image_url: string;
  thumbnail_url: string;
  title: string;
  created_at: string;
}

export interface GalleryListResponse {
  success: boolean;
  items: GalleryItem[];
  has_more: boolean;
}

export interface UploadImageResponse {
  success: boolean;
  image_url?: string;
  message?: string;
}

export interface DeleteGalleryResponse {
  success: boolean;
  message?: string;
}