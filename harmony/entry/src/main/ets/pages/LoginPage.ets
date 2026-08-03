import { ApiService } from '../service/ApiService';
import { AuthService } from '../service/AuthService';
import { Constants } from '../common/Constants';
import { LoginResponse } from '../model/UserData';
import { UserData } from '../model/UserData';

@Component
export struct LoginPage {
  private onLoginSuccess: () => void = () => {};

  @State username: string = '';
  @State password: string = '';
  @State isLoading: boolean = false;
  @State errorMessage: string = '';
  @State isRegister: boolean = false;

  build() {
    Column({ space: 20 }) {
      Column({ space: 8 }) {
        Text('默写史记')
          .fontSize(36)
          .fontWeight(FontWeight.Bold)
          .fontColor('#2d2d2d');

        Text('ListenWrite')
          .fontSize(16)
          .fontColor('#999999');
      }
      .margin({ top: 80, bottom: 40 });

      Column({ space: 12 }) {
        TextInput({ placeholder: '用户名', text: this.username })
          .width('100%')
          .height(48)
          .padding({ left: 16, right: 16 })
          .backgroundColor('#ffffff')
          .border({ width: 2, color: '#2d2d2d', style: BorderStyle.Solid })
          .borderRadius(12)
          .onChange((value: string) => {
            this.username = value;
          });

        TextInput({ placeholder: '密码', text: this.password })
          .width('100%')
          .height(48)
          .padding({ left: 16, right: 16 })
          .backgroundColor('#ffffff')
          .border({ width: 2, color: '#2d2d2d', style: BorderStyle.Solid })
          .borderRadius(12)
          .type(InputType.Password)
          .onChange((value: string) => {
            this.password = value;
          });

        Button(this.isRegister ? '注册' : '登录')
          .width('100%')
          .height(48)
          .backgroundColor('#2d5da1')
          .fontColor('#ffffff')
          .fontSize(18)
          .fontWeight(FontWeight.Bold)
          .borderRadius(12)
          .enabled(!this.isLoading)
          .onClick(() => {
            this.handleLogin();
          });

        if (this.errorMessage.length > 0) {
          Text(this.errorMessage)
            .fontSize(14)
            .fontColor('#ff4d4d')
            .textAlign(TextAlign.Center)
            .width('100%');
        }

        Text(this.isRegister ? '已有账号？去登录' : '没有账号？去注册')
          .fontSize(14)
          .fontColor('#2d5da1')
          .textAlign(TextAlign.Center)
          .width('100%')
          .onClick(() => {
            this.isRegister = !this.isRegister;
            this.errorMessage = '';
          });
      }
      .width('85%');
    }
    .width('100%')
    .height('100%')
    .backgroundColor('#fdfbf7')
    .alignItems(HorizontalAlign.Center);
  }

  async handleLogin(): Promise<void> {
    if (this.username.length === 0 || this.password.length === 0) {
      this.errorMessage = '请输入用户名和密码';
      return;
    }

    this.isLoading = true;
    this.errorMessage = '';

    try {
      let api: ApiService = ApiService.getInstance();
      let action: string = this.isRegister ? 'register' : 'login';
      let response: LoginResponse = await api.request<LoginResponse>(action, {
        username: this.username,
        password: this.password,
      });

      if (response.success) {
        let user: UserData = {
          id: response.user_id ?? 0,
          username: response.username ?? this.username,
          token: response.token ?? '',
        };
        let authService: AuthService = AuthService.getInstance();
        await authService.saveUser(user);
        api.setToken(user.token);
        this.onLoginSuccess();
      } else {
        this.errorMessage = response.message ?? '操作失败';
      }
    } catch (e) {
      this.errorMessage = '网络错误，请稍后重试';
    } finally {
      this.isLoading = false;
    }
  }
}