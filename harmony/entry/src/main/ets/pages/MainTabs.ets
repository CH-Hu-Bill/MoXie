import { StudyPage } from './StudyPage';
import { LifePage } from './LifePage';
import { SearchPage } from './SearchPage';
import { GalleryPage } from './GalleryPage';
import { ProfilePage } from './ProfilePage';

@Component
export struct MainTabs {
  private onLogout: () => void = () => {};

  @State currentIndex: number = 0;

  build() {
    Tabs({ barPosition: BarPosition.End, index: this.currentIndex }) {
      TabContent() {
        StudyPage();
      }
      .tabBar(this.buildTabBarItem(0, '学习'))

      TabContent() {
        LifePage();
      }
      .tabBar(this.buildTabBarItem(1, '生活'))

      TabContent() {
        SearchPage();
      }
      .tabBar(this.buildTabBarItem(2, '搜索'))

      TabContent() {
        GalleryPage();
      }
      .tabBar(this.buildTabBarItem(3, '画廊'))

      TabContent() {
        ProfilePage({
          onLogout: () => {
            this.onLogout();
          }
        });
      }
      .tabBar(this.buildTabBarItem(4, '我的'))
    }
    .vertical(false)
    .scrollable(false)
    .barWidth('100%')
    .barHeight(56)
    .backgroundColor('#ffffff')
    .onChange((index: number) => {
      this.currentIndex = index;
    });
  }

  @Builder
  buildTabBarItem(index: number, label: string): void {
    Column({ space: 2 }) {
      Text(this.getIcon(index))
        .fontSize(22)
        .fontColor(this.currentIndex === index ? '#2d5da1' : '#999999');
      Text(label)
        .fontSize(10)
        .fontColor(this.currentIndex === index ? '#2d5da1' : '#999999');
    }
    .width('100%')
    .height(56)
    .justifyContent(FlexAlign.Center);
  }

  getIcon(index: number): string {
    let icons: string[] = ['📖', '📝', '🔍', '🖼️', '👤'];
    return icons[index];
  }
}