import { ApiService } from '../service/ApiService';
import { Constants } from '../common/Constants';
import { SaveVlogResponse } from '../model/VlogData';

@Component
export struct VlogEditorPage {
  private date: string = '';
  private onSaved: () => void = () => {};

  @State title: string = '';
  @State content: string = '';
  @State isSaving: boolean = false;

  aboutToAppear(): void {
    if (this.date.length === 0) {
      let now: Date = new Date();
      this.date = now.getFullYear() + '-' +
        this.pad(now.getMonth() + 1) + '-' +
        this.pad(now.getDate());
    }
  }

  build() {
    Column({ space: 12 }) {
      Row({ space: 12 }) {
        Text('编辑日志')
          .fontSize(20)
          .fontWeight(FontWeight.Bold)
          .fontColor('#2d2d2d');

        Text(this.date)
          .fontSize(14)
          .fontColor('#999999');
      }
      .width('100%')
      .padding(16);

      TextInput({ placeholder: '标题', text: this.title })
        .width('90%')
        .height(44)
        .padding({ left: 12 })
        .backgroundColor('#ffffff')
        .border({ width: 2, color: '#2d2d2d', style: BorderStyle.Solid })
        .borderRadius(12)
        .onChange((value: string) => {
          this.title = value;
        });

      RichEditor({ placeholder: '写点什么...' })
        .width('90%')
        .layoutWeight(1)
        .backgroundColor('#ffffff')
        .border({ width: 2, color: '#2d2d2d', style: BorderStyle.Solid })
        .borderRadius(12);

      Row({ space: 12 }) {
        Button('取消')
          .height(44)
          .backgroundColor('#e0e0e0')
          .fontColor('#2d2d2d')
          .fontSize(16)
          .borderRadius(12)
          .onClick(() => {
            this.onSaved();
          });

        Button('保存')
          .height(44)
          .backgroundColor('#2d5da1')
          .fontColor('#ffffff')
          .fontSize(16)
          .fontWeight(FontWeight.Bold)
          .borderRadius(12)
          .loading(this.isSaving)
          .onClick(() => {
            this.handleSave();
          });
      }
      .width('90%')
      .justifyContent(FlexAlign.End);
    }
    .width('100%')
    .height('100%')
    .backgroundColor('#fdfbf7')
    .alignItems(HorizontalAlign.Center);
  }

  pad(num: number): string {
    return num < 10 ? '0' + num.toString() : num.toString();
  }

  async handleSave(): Promise<void> {
    if (this.title.length === 0) {
      return;
    }
    this.isSaving = true;
    try {
      let api: ApiService = ApiService.getInstance();
      let response: SaveVlogResponse = await api.request<SaveVlogResponse>('save_personal_history', {
        date: this.date,
        title: this.title,
        content: this.content,
      });
      if (response.success) {
        this.onSaved();
      }
    } catch (e) {
    } finally {
      this.isSaving = false;
    }
  }
}