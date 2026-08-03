import { ApiService } from '../service/ApiService';
import { Constants } from '../common/Constants';
import { GalleryItem, GalleryListResponse, DeleteGalleryResponse } from '../model/GalleryData';

@Component
export struct GalleryPage {
  @State items: GalleryItem[] = [];
  @State isLoading: boolean = false;
  @State hasMore: boolean = true;
  @State page: number = 1;
  @State showFullscreen: boolean = false;
  @State fullscreenUrl: string = '';

  aboutToAppear(): void {
    this.loadGallery();
  }

  build() {
    Stack() {
      Column({ space: 0 }) {
        if (this.isLoading && this.items.length === 0) {
          LoadingProgress().color('#2d5da1').width(36).height(36).margin({ top: 40 });
        } else if (this.items.length === 0) {
          Column({ space: 12 }) {
            Text('暂无图片')
              .fontSize(16)
              .fontColor('#999999')
              .margin({ top: 40 });
            Button('上传图片')
              .height(40)
              .backgroundColor('#2d5da1')
              .fontColor('#ffffff')
              .borderRadius(12)
              .onClick(() => {
                this.uploadImage();
              });
          }
          .width('100%');
        } else {
          Grid() {
            ForEach(this.items, (item: GalleryItem) => {
              GridItem() {
                Column() {
                  Image(item.thumbnail_url)
                    .width('100%')
                    .aspectRatio(1)
                    .objectFit(ImageFit.Cover)
                    .borderRadius(8);
                }
                .width('100%')
                .padding(4)
                .onClick(() => {
                  this.fullscreenUrl = item.image_url;
                  this.showFullscreen = true;
                });
              }
            }, (item: GalleryItem) => item.id.toString());
          }
          .columnsTemplate('1fr 1fr 1fr')
          .rowsAuto(true)
          .width('100%')
          .layoutWeight(1)
          .padding(8);
        }
      }
      .width('100%')
      .height('100%')
      .backgroundColor('#fdfbf7');

      if (this.showFullscreen) {
        Column() {
          Image(this.fullscreenUrl)
            .width('100%')
            .height('100%')
            .objectFit(ImageFit.Contain)
            .onClick(() => {
              this.showFullscreen = false;
            });
        }
        .width('100%')
        .height('100%')
        .backgroundColor('#000000')
        .onClick(() => {
          this.showFullscreen = false;
        });
      }
    }
    .width('100%')
    .height('100%');
  }

  async loadGallery(): Promise<void> {
    this.isLoading = true;
    try {
      let api: ApiService = ApiService.getInstance();
      let response: GalleryListResponse = await api.request<GalleryListResponse>('get_gallery', {
        page: this.page.toString(),
        page_size: Constants.PAGE_SIZE.toString(),
      });
      if (response.success) {
        this.items = response.items;
        this.hasMore = response.has_more;
      }
    } catch (e) {
    } finally {
      this.isLoading = false;
    }
  }

  async uploadImage(): Promise<void> {
    // Image picker + upload would go here
    // For now, show a placeholder
  }
}