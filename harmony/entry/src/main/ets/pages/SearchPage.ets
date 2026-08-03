import { ApiService } from '../service/ApiService';
import { Constants } from '../common/Constants';
import { SearchApiResponse, SearchResultItem } from '../model/CommonData';

@Component
export struct SearchPage {
  @State keyword: string = '';
  @State searchResults: SearchResultItem[] = [];
  @State isLoading: boolean = false;
  @State hasSearched: boolean = false;

  build() {
    Column({ space: 12 }) {
      Search({ value: this.keyword, placeholder: '搜索单词、任务...' })
        .width('90%')
        .height(44)
        .backgroundColor('#ffffff')
        .border({ width: 2, color: '#2d2d2d', style: BorderStyle.Solid })
        .borderRadius(12)
        .onChange((value: string) => {
          this.keyword = value;
        })
        .onSubmit(() => {
          this.doSearch();
        });

      if (this.isLoading) {
        LoadingProgress().color('#2d5da1').width(36).height(36).margin({ top: 20 });
      } else if (this.hasSearched && this.searchResults.length === 0) {
        Text('未找到结果')
          .fontSize(16)
          .fontColor('#999999')
          .margin({ top: 40 });
      } else {
        List({ space: 8 }) {
          ForEach(this.searchResults, (item: SearchResultItem) => {
            ListItem() {
              Column({ space: 4 }) {
                Text(item.type === 'word' ? '📖' : '📋')
                  .fontSize(16);
                Text(item.title)
                  .fontSize(16)
                  .fontWeight(FontWeight.Bold)
                  .fontColor('#2d2d2d');
                Text(item.subtitle)
                  .fontSize(13)
                  .fontColor('#666666');
              }
              .width('100%')
              .padding(14)
              .backgroundColor('#ffffff')
              .border({ width: 2, color: '#2d2d2d', style: BorderStyle.Solid })
              .borderRadius(12);
            }
          }, (item: SearchResultItem) => item.id.toString());
        }
        .width('100%')
        .layoutWeight(1)
        .padding({ left: 12, right: 12 });
      }
    }
    .width('100%')
    .height('100%')
    .backgroundColor('#fdfbf7')
    .alignItems(HorizontalAlign.Center)
    .padding({ top: 12 });
  }

  async doSearch(): Promise<void> {
    if (this.keyword.length === 0) {
      return;
    }
    this.isLoading = true;
    this.hasSearched = true;
    try {
      let api: ApiService = ApiService.getInstance();
      let response: SearchApiResponse = await api.request<SearchApiResponse>('search_all', {
        keyword: this.keyword,
      });
      if (response.success) {
        this.searchResults = response.results ?? [];
      }
    } catch (e) {
      this.searchResults = [];
    } finally {
      this.isLoading = false;
    }
  }
}