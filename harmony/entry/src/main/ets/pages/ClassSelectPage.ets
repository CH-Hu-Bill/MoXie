import { ApiService } from '../service/ApiService';
import { AuthService } from '../service/AuthService';
import { Constants } from '../common/Constants';
import { ClassInfo, ClassesResponse, BindClassResponse } from '../model/UserData';

@Component
export struct ClassSelectPage {
  private onClassSelected: () => void = () => {};

  @State classes: ClassInfo[] = [];
  @State isLoading: boolean = true;
  @State showBindDialog: boolean = false;
  @State bindClassCode: string = '';
  @State bindPassword: string = '';
  @State bindError: string = '';

  aboutToAppear(): void {
    this.loadClasses();
  }

  build() {
    Stack() {
      Column({ space: 16 }) {
        Text('选择班级')
          .fontSize(24)
          .fontWeight(FontWeight.Bold)
          .fontColor('#2d2d2d')
          .margin({ top: 60, bottom: 20 });

        if (this.isLoading) {
          LoadingProgress()
            .color('#2d5da1')
            .width(36)
            .height(36);
        } else {
          List({ space: 12 }) {
            ForEach(this.classes, (item: ClassInfo) => {
              ListItem() {
                Column({ space: 8 }) {
                  Text(item.class_name)
                    .fontSize(18)
                    .fontWeight(FontWeight.Bold)
                    .fontColor('#2d2d2d');

                  Text(item.subject + ' · ' + item.level)
                    .fontSize(14)
                    .fontColor('#999999');
                }
                .width('100%')
                .padding(16)
                .backgroundColor('#ffffff')
                .border({ width: 2, color: '#2d2d2d', style: BorderStyle.Solid })
                .borderRadius(12)
                .onClick(() => {
                  this.selectClass(item);
                });
              }
            }, (item: ClassInfo) => item.class_id.toString());
          }
          .width('85%')
          .layoutWeight(1);

          Button('绑定新班级')
            .width('85%')
            .height(48)
            .backgroundColor('#ff4d4d')
            .fontColor('#ffffff')
            .fontSize(16)
            .fontWeight(FontWeight.Bold)
            .borderRadius(12)
            .margin({ bottom: 40 })
            .onClick(() => {
              this.showBindDialog = true;
            });
        }
      }
      .width('100%')
      .height('100%')
      .backgroundColor('#fdfbf7')
      .alignItems(HorizontalAlign.Center);

      if (this.showBindDialog) {
        Column({ space: 12 }) {
          Text('绑定班级')
            .fontSize(20)
            .fontWeight(FontWeight.Bold)
            .fontColor('#2d2d2d');

          TextInput({ placeholder: '班级代码', text: this.bindClassCode })
            .width('100%')
            .height(44)
            .padding({ left: 12 })
            .border({ width: 1, color: '#2d2d2d', style: BorderStyle.Solid })
            .borderRadius(8)
            .onChange((value: string) => {
              this.bindClassCode = value;
            });

          TextInput({ placeholder: '班级密码', text: this.bindPassword })
            .width('100%')
            .height(44)
            .padding({ left: 12 })
            .border({ width: 1, color: '#2d2d2d', style: BorderStyle.Solid })
            .borderRadius(8)
            .type(InputType.Password)
            .onChange((value: string) => {
              this.bindPassword = value;
            });

          if (this.bindError.length > 0) {
            Text(this.bindError)
              .fontSize(14)
              .fontColor('#ff4d4d');
          }

          Row({ space: 12 }) {
            Button('取消')
              .height(40)
              .backgroundColor('#e0e0e0')
              .fontColor('#2d2d2d')
              .borderRadius(8)
              .onClick(() => {
                this.showBindDialog = false;
                this.bindError = '';
              });

            Button('绑定')
              .height(40)
              .backgroundColor('#2d5da1')
              .fontColor('#ffffff')
              .borderRadius(8)
              .onClick(() => {
                this.handleBindClass();
              });
          }
        }
        .width('80%')
        .padding(24)
        .backgroundColor('#ffffff')
        .borderRadius(16)
        .shadow({ radius: 8, offsetX: 0, offsetY: 4, color: '#00000033', fill: false });
      }
    }
    .width('100%')
    .height('100%');
  }

  async loadClasses(): Promise<void> {
    this.isLoading = true;
    try {
      let api: ApiService = ApiService.getInstance();
      let response: ClassesResponse = await api.request<ClassesResponse>('get_my_classes');
      if (response.success) {
        this.classes = response.classes;
      }
    } catch (e) {
      this.classes = [];
    } finally {
      this.isLoading = false;
    }
  }

  async selectClass(classInfo: ClassInfo): Promise<void> {
    let authService: AuthService = AuthService.getInstance();
    await authService.saveClassId(classInfo.class_id);
    this.onClassSelected();
  }

  async handleBindClass(): Promise<void> {
    if (this.bindClassCode.length === 0 || this.bindPassword.length === 0) {
      this.bindError = '请填写班级代码和密码';
      return;
    }
    this.bindError = '';
    try {
      let api: ApiService = ApiService.getInstance();
      let response: BindClassResponse = await api.request<BindClassResponse>('bind_class', {
        class_code: this.bindClassCode,
        password: this.bindPassword,
      });
      if (response.success) {
        this.showBindDialog = false;
        this.bindClassCode = '';
        this.bindPassword = '';
        await this.loadClasses();
      } else {
        this.bindError = response.message ?? '绑定失败';
      }
    } catch (e) {
      this.bindError = '网络错误，请稍后重试';
    }
  }
}