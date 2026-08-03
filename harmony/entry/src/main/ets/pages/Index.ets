import { AuthService } from '../service/AuthService';
import { LoginPage } from './LoginPage';
import { ClassSelectPage } from './ClassSelectPage';
import { MainTabs } from './MainTabs';

@Entry
@Component
struct Index {
  @State currentPage: string = 'loading';

  aboutToAppear(): void {
    let authService: AuthService = AuthService.getInstance();
    if (authService.isLoggedIn()) {
      let classId: number = 0;
      authService.getClassId().then((id: number) => {
        classId = id;
        if (classId > 0) {
          this.currentPage = 'main';
        } else {
          this.currentPage = 'classSelect';
        }
      });
    } else {
      this.currentPage = 'login';
    }
  }

  build() {
    Stack() {
      if (this.currentPage === 'loading') {
        LoadingProgress()
          .color('#2d5da1')
          .width(48)
          .height(48);
      } else if (this.currentPage === 'login') {
        LoginPage({
          onLoginSuccess: () => {
            this.currentPage = 'classSelect';
          }
        });
      } else if (this.currentPage === 'classSelect') {
        ClassSelectPage({
          onClassSelected: () => {
            this.currentPage = 'main';
          }
        });
      } else if (this.currentPage === 'main') {
        MainTabs({
          onLogout: () => {
            this.currentPage = 'login';
          }
        });
      }
    }
    .width('100%')
    .height('100%')
    .backgroundColor('#fdfbf7');
  }
}