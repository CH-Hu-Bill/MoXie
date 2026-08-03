import { ApiService } from '../service/ApiService';
import { AuthService } from '../service/AuthService';
import { TtsService } from '../service/TtsService';
import { Constants } from '../common/Constants';
import { WordItem, WordListResponse, ToggleFavoriteResponse, ToggleWrongResponse } from '../model/WordData';
import { TaskItem, TaskListResponse } from '../model/TaskData';

@Component
export struct StudyPage {
  @State currentTab: number = 0;
  @State words: WordItem[] = [];
  @State wrongWords: WordItem[] = [];
  @State tasks: TaskItem[] = [];
  @State isLoading: boolean = false;
  @State isRefreshing: boolean = false;
  @State hasMoreWords: boolean = true;
  @State hasMoreWrong: boolean = true;
  @State hasMoreTasks: boolean = true;
  @State wordPage: number = 1;
  @State wrongPage: number = 1;
  @State taskPage: number = 1;
  @State selectedWord: WordItem | null = null;
  @State showWordDetail: boolean = false;
  @State showAddWord: boolean = false;
  @State newWord: string = '';
  @State newMeaning: string = '';

  aboutToAppear(): void {
    this.loadWords();
    this.loadWrongWords();
    this.loadTasks();
  }

  async onRefresh(): Promise<void> {
    this.isRefreshing = true;
    this.wordPage = 1;
    this.wrongPage = 1;
    this.taskPage = 1;
    await this.loadWords();
    await this.loadWrongWords();
    await this.loadTasks();
    this.isRefreshing = false;
  }

  build() {
    Column({ space: 0 }) {
      Row({ space: 0 }) {
        ForEach(['单词库', '错题本', '任务'], (tab: string, index: number) => {
          Column() {
            Text(tab)
              .fontSize(14)
              .fontColor(this.currentTab === index ? '#2d5da1' : '#999999')
              .fontWeight(this.currentTab === index ? FontWeight.Bold : FontWeight.Normal);
            if (this.currentTab === index) {
              Divider()
                .width('80%')
                .height(2)
                .color('#2d5da1')
                .margin({ top: 4 });
            }
          }
          .width(100 / 3)
          .height(40)
          .justifyContent(FlexAlign.Center)
          .onClick(() => {
            this.currentTab = index;
          });
        });
      }
      .width('100%')
      .backgroundColor('#ffffff')
      .border({ bottom: { width: 1, color: '#e0e0e0' } });

      if (this.currentTab === 0) {
        this.buildWordLibrary();
      } else if (this.currentTab === 1) {
        this.buildWrongWords();
      } else {
        this.buildTaskList();
      }
    }
    .width('100%')
    .height('100%')
    .backgroundColor('#fdfbf7');
  }

  @Builder
  buildWordLibrary(): void {
    Refresh({ refreshing: $$this.isRefreshing }) {
      Column() {
        if (this.isLoading && this.words.length === 0) {
          LoadingProgress().color('#2d5da1').width(36).height(36).margin({ top: 40 });
        } else {
          List({ space: 8 }) {
            ForEach(this.words, (item: WordItem) => {
              ListItem() {
                this.buildWordCard(item);
              }
            }, (item: WordItem) => item.id.toString());
          }
          .width('100%')
          .layoutWeight(1)
          .padding(12);

          Button('+ 添加单词')
            .width('90%')
            .height(44)
            .backgroundColor('#2d5da1')
            .fontColor('#ffffff')
            .fontSize(16)
            .borderRadius(12)
            .margin({ bottom: 12 })
            .onClick(() => {
              this.showAddWord = true;
            });
        }
      }
      .width('100%')
      .height('100%');
    }
    .onRefresh(() => {
      this.onRefresh();
    });
  }

  @Builder
  buildWrongWords(): void {
    if (this.isLoading && this.wrongWords.length === 0) {
      LoadingProgress().color('#2d5da1').width(36).height(36).margin({ top: 40 });
    } else if (this.wrongWords.length === 0) {
      Text('暂无错题')
        .fontSize(16)
        .fontColor('#999999')
        .margin({ top: 40 });
    } else {
      List({ space: 8 }) {
        ForEach(this.wrongWords, (item: WordItem) => {
          ListItem() {
            this.buildWordCard(item);
          }
        }, (item: WordItem) => item.id.toString());
      }
      .width('100%')
      .layoutWeight(1)
      .padding(12);
    }
  }

  @Builder
  buildTaskList(): void {
    if (this.isLoading && this.tasks.length === 0) {
      LoadingProgress().color('#2d5da1').width(36).height(36).margin({ top: 40 });
    } else if (this.tasks.length === 0) {
      Text('暂无任务')
        .fontSize(16)
        .fontColor('#999999')
        .margin({ top: 40 });
    } else {
      List({ space: 8 }) {
        ForEach(this.tasks, (item: TaskItem) => {
          ListItem() {
            Column({ space: 4 }) {
              Text(item.title)
                .fontSize(16)
                .fontWeight(FontWeight.Bold)
                .fontColor('#2d2d2d');
              Text(item.date + ' · ' + item.word_count + '词')
                .fontSize(13)
                .fontColor('#999999');
              Text(item.status === 'completed' ? '✅ 已完成' : '⏳ 进行中')
                .fontSize(13)
                .fontColor(item.status === 'completed' ? '#4caf50' : '#ff4d4d');
            }
            .width('100%')
            .padding(16)
            .backgroundColor('#ffffff')
            .border({ width: 2, color: '#2d2d2d', style: BorderStyle.Solid })
            .borderRadius(12);
          }
        }, (item: TaskItem) => item.id.toString());
      }
      .width('100%')
      .layoutWeight(1)
      .padding(12);
    }
  }

  @Builder
  buildWordCard(item: WordItem): void {
    Row({ space: 12 }) {
      Column({ space: 4 }) {
        Text(item.word)
          .fontSize(18)
          .fontWeight(FontWeight.Bold)
          .fontColor('#2d2d2d');
        Text(item.meaning)
          .fontSize(14)
          .fontColor('#666666');
      }
      .layoutWeight(1)
      .alignItems(HorizontalAlign.Start);

      Text('🔊')
        .fontSize(20)
        .onClick(() => {
          let tts: TtsService = TtsService.getInstance();
          tts.playWord(item.word);
        });

      Text(item.is_wrong ? '❌' : '✖️')
        .fontSize(20)
        .onClick(() => {
          this.toggleWrong(item);
        });
    }
    .width('100%')
    .padding(14)
    .backgroundColor('#ffffff')
    .border({
      width: 2,
      color: item.is_wrong ? '#ff4d4d' : '#2d2d2d',
      style: BorderStyle.Solid,
    })
    .borderRadius(12);
  }

  async loadWords(): Promise<void> {
    this.isLoading = true;
    try {
      let api: ApiService = ApiService.getInstance();
      let auth: AuthService = AuthService.getInstance();
      let classId: number = await auth.getClassId();
      let response: WordListResponse = await api.request<WordListResponse>('get_words', {
        class_id: classId.toString(),
        page: this.wordPage.toString(),
        page_size: Constants.PAGE_SIZE.toString(),
      });
      if (response.success) {
        this.words = response.words;
        this.hasMoreWords = response.has_more;
      }
    } catch (e) {
    } finally {
      this.isLoading = false;
    }
  }

  async loadWrongWords(): Promise<void> {
    try {
      let api: ApiService = ApiService.getInstance();
      let response: WordListResponse = await api.request<WordListResponse>('get_wrong_words', {
        page: this.wrongPage.toString(),
        page_size: Constants.PAGE_SIZE.toString(),
      });
      if (response.success) {
        this.wrongWords = response.words;
        this.hasMoreWrong = response.has_more;
      }
    } catch (e) {
    }
  }

  async loadTasks(): Promise<void> {
    try {
      let api: ApiService = ApiService.getInstance();
      let response: TaskListResponse = await api.request<TaskListResponse>('get_tasks', {
        page: this.taskPage.toString(),
        page_size: Constants.PAGE_SIZE.toString(),
      });
      if (response.success) {
        this.tasks = response.tasks;
        this.hasMoreTasks = response.has_more;
      }
    } catch (e) {
    }
  }

  async toggleWrong(item: WordItem): Promise<void> {
    try {
      let api: ApiService = ApiService.getInstance();
      let action: string = item.is_wrong ? 'unmark_wrong' : 'mark_wrong';
      let response: ToggleWrongResponse = await api.request<ToggleWrongResponse>(action, {
        word_id: item.id.toString(),
      });
      if (response.success) {
        item.is_wrong = response.is_wrong;
      }
    } catch (e) {
    }
  }
}