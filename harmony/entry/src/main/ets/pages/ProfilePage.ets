import { ApiService } from '../service/ApiService';
import { AuthService } from '../service/AuthService';
import { Constants } from '../common/Constants';
import { UserData } from '../model/UserData';
import { VersionCheckResponse } from '../model/CommonData';

@Component
export struct ProfilePage {
  private onLogout: () => void = () => {};

  @State user: UserData | null = null;
  @State classId: number = 0;
  @State showLogoutConfirm: boolean = false;

  aboutToAppear(): void {
    let authService: AuthService = AuthService.getInstance();
    this.user = authService.getUser();
    authService.getClassId().then((id: number) => {
      this.classId = id;
    });
  }

  build() {
    Stack() {
      Column({ space: 20 }) {
        Column({ space: 12 }) {
          Circle()
            .width(72)
            .height(72)
            .fill('#2d5da1')
            .margin({ top: 40 });

          Text(this.user?.username ?? '未知用户')
            .fontSize(20)
            .fontWeight(FontWeight.Bold)
            .fontColor('#2d2d2d');

          Text('班级ID: ' + this.classId.toString())
            .fontSize(14)
            .fontColor('#999999');
        }
        .width('100%')
        .alignItems(HorizontalAlign.Center);

        Column({ space: 12 }) {
          Button('清除缓存')
            .width('85%')
            .height(48)
            .backgroundColor('#ffffff')
            .fontColor('#2d2d2d')
            .fontSize(16)
            .border({ width: 2, color: '#2d2d2d', style: BorderStyle.Solid })
            .borderRadius(12)
            .onClick(() => {
            });

          Button('检查版本更新')
            .width('85%')
            .height(48)
            .backgroundColor('#ffffff')
            .fontColor('#2d2d2d')
            .fontSize(16)
            .border({ width: 2, color: '#2d2d2d', style: BorderStyle.Solid })
            .borderRadius(12)
            .onClick(() => {
              this.checkVersion();
            });

          Button('退出登录')
            .width('85%')
            .height(48)
            .backgroundColor('#ff4d4d')
            .fontColor('#ffffff')
            .fontSize(16)
            .fontWeight(FontWeight.Bold)
            .borderRadius(12)
            .onClick(() => {
              this.showLogoutConfirm = true;
            });
        }
        .width('100%')
        .alignItems(HorizontalAlign.Center)
        .margin({ top: 20 });
      }
      .width('100%')
      .height('100%')
      .backgroundColor('#fdfbf7')
      .alignItems(HorizontalAlign.Center);

      if (this.showLogoutConfirm) {
        Column({ space: 16 }) {
          Text('确认退出登录？')
            .fontSize(18)
            .fontWeight(FontWeight.Bold)
            .fontColor('#2d2d2d');

          Text('退出后需要重新登录')
            .fontSize(14)
            .fontColor('#999999');

          Row({ space: 12 }) {
            Button('取消')
              .height(40)
              .backgroundColor('#e0e0e0')
              .fontColor('#2d2d2d')
              .borderRadius(8)
              .onClick(() => {
                this.showLogoutConfirm = false;
              });

            Button('确认退出')
              .height(40)
              .backgroundColor('#ff4d4d')
              .fontColor('#ffffff')
              .borderRadius(8)
              .onClick(() => {
                this.handleLogout();
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

  async handleLogout(): Promise<void> {
    let authService: AuthService = AuthService.getInstance();
    await authService.logout();
    this.showLogoutConfirm = false;
    this.onLogout();
  }

  async checkVersion(): Promise<void> {
    try {
      let api: ApiService = ApiService.getInstance();
      let response: VersionCheckResponse = await api.request<VersionCheckResponse>('check_version');
    } catch (e) {
    }
  }
}