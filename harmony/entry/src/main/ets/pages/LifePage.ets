import { ApiService } from '../service/ApiService';
import { AuthService } from '../service/AuthService';
import { Constants } from '../common/Constants';
import { VlogEntry, VlogListResponse } from '../model/VlogData';

@Component
export struct LifePage {
  @State currentYear: number = 2026;
  @State currentMonth: number = 8;
  @State selectedDate: string = '';
  @State entries: VlogEntry[] = [];
  @State isLoading: boolean = false;
  @State currentVlogTab: number = 0;
  @State showEditor: boolean = false;
  @State editorContent: string = '';

  aboutToAppear(): void {
    let now: Date = new Date();
    this.currentYear = now.getFullYear();
    this.currentMonth = now.getMonth() + 1;
    let today: string = this.formatDate(now);
    this.selectedDate = today;
    this.loadEntries(today);
  }

  build() {
    Column({ space: 0 }) {
      Row({ space: 0 }) {
        ForEach(['我的', '他人', '班级'], (tab: string, index: number) => {
          Column() {
            Text(tab)
              .fontSize(14)
              .fontColor(this.currentVlogTab === index ? '#2d5da1' : '#999999')
              .fontWeight(this.currentVlogTab === index ? FontWeight.Bold : FontWeight.Normal);
            if (this.currentVlogTab === index) {
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
            this.currentVlogTab = index;
            this.loadEntries(this.selectedDate);
          });
        });
      }
      .width('100%')
      .backgroundColor('#ffffff')
      .border({ bottom: { width: 1, color: '#e0e0e0' } });

      this.buildCalendar();

      Divider().height(1).color('#e0e0e0').width('100%');

      if (this.isLoading) {
        LoadingProgress().color('#2d5da1').width(36).height(36).margin({ top: 20 });
      } else if (this.entries.length === 0) {
        Column({ space: 12 }) {
          Text('暂无内容')
            .fontSize(16)
            .fontColor('#999999')
            .margin({ top: 20 });
          Button('写日志')
            .height(40)
            .backgroundColor('#2d5da1')
            .fontColor('#ffffff')
            .borderRadius(12)
            .onClick(() => {
              this.showEditor = true;
            });
        }
        .width('100%');
      } else {
        List({ space: 8 }) {
          ForEach(this.entries, (entry: VlogEntry) => {
            ListItem() {
              Column({ space: 8 }) {
                Row({ space: 8 }) {
                  Text(entry.author)
                    .fontSize(14)
                    .fontWeight(FontWeight.Bold)
                    .fontColor('#2d5da1');
                  Text(entry.date)
                    .fontSize(12)
                    .fontColor('#999999');
                }
                .width('100%');

                Text(entry.title)
                  .fontSize(16)
                  .fontWeight(FontWeight.Bold)
                  .fontColor('#2d2d2d');

                Text(entry.content)
                  .fontSize(14)
                  .fontColor('#666666')
                  .maxLines(3)
                  .textOverflow({ overflow: TextOverflow.Ellipsis });

                if (entry.is_owner) {
                  Row({ space: 8 }) {
                    Text('编辑')
                      .fontSize(13)
                      .fontColor('#2d5da1')
                      .onClick(() => {
                        this.editorContent = entry.content;
                        this.showEditor = true;
                      });
                  }
                  .width('100%');
                }
              }
              .width('100%')
              .padding(14)
              .backgroundColor('#ffffff')
              .border({ width: 2, color: '#2d2d2d', style: BorderStyle.Solid })
              .borderRadius(12);
            }
          }, (entry: VlogEntry) => entry.id.toString());
        }
        .width('100%')
        .layoutWeight(1)
        .padding(12);
      }
    }
    .width('100%')
    .height('100%')
    .backgroundColor('#fdfbf7');
  }

  @Builder
  buildCalendar(): void {
    Column({ space: 8 }) {
      Row({ space: 16 }) {
        Text('<')
          .fontSize(20)
          .fontColor('#2d5da1')
          .onClick(() => {
            this.currentMonth--;
            if (this.currentMonth < 1) {
              this.currentMonth = 12;
              this.currentYear--;
            }
          });

        Text(this.currentYear + '年' + this.currentMonth + '月')
          .fontSize(16)
          .fontWeight(FontWeight.Bold)
          .fontColor('#2d2d2d');

        Text('>')
          .fontSize(20)
          .fontColor('#2d5da1')
          .onClick(() => {
            this.currentMonth++;
            if (this.currentMonth > 12) {
              this.currentMonth = 1;
              this.currentYear++;
            }
          });
      }
      .width('100%')
      .justifyContent(FlexAlign.Center);

      Grid() {
        ForEach(['日', '一', '二', '三', '四', '五', '六'], (day: string) => {
          GridItem() {
            Text(day)
              .fontSize(12)
              .fontColor('#999999')
              .textAlign(TextAlign.Center);
          }
        });

        let daysInMonth: number = this.getDaysInMonth(this.currentYear, this.currentMonth);
        let firstDay: number = new Date(this.currentYear, this.currentMonth - 1, 1).getDay();
        let totalCells: number = firstDay + daysInMonth;
        let rows: number = Math.ceil(totalCells / 7);

        ForEach(this.buildCalendarDays(firstDay, daysInMonth), (dayNum: number) => {
          GridItem() {
            if (dayNum > 0) {
              let dateStr: string = this.currentYear + '-' +
                this.pad(this.currentMonth) + '-' +
                this.pad(dayNum);
              let isSelected: boolean = dateStr === this.selectedDate;
              let isToday: boolean = this.isToday(dateStr);

              Text(dayNum.toString())
                .fontSize(14)
                .fontColor(isSelected ? '#ffffff' : (isToday ? '#ff4d4d' : '#2d2d2d'))
                .fontWeight(isToday ? FontWeight.Bold : FontWeight.Normal)
                .textAlign(TextAlign.Center)
                .width(32)
                .height(32)
                .borderRadius(16)
                .backgroundColor(isSelected ? '#2d5da1' : 'transparent')
                .onClick(() => {
                  this.selectedDate = dateStr;
                  this.loadEntries(dateStr);
                });
            }
          }
        }, (dayNum: number) => dayNum.toString());
      }
      .columnsTemplate('1fr 1fr 1fr 1fr 1fr 1fr 1fr')
      .rowsTemplate('1fr 1fr 1fr 1fr 1fr 1fr')
      .width('100%')
      .padding(8);
    }
    .width('100%')
    .padding(12)
    .backgroundColor('#ffffff');
  }

  buildCalendarDays(firstDay: number, daysInMonth: number): number[] {
    let days: number[] = [];
    for (let i: number = 0; i < firstDay; i++) {
      days.push(-1);
    }
    for (let d: number = 1; d <= daysInMonth; d++) {
      days.push(d);
    }
    let remainder: number = days.length % 7;
    if (remainder > 0) {
      for (let i: number = 0; i < 7 - remainder; i++) {
        days.push(-1);
      }
    }
    return days;
  }

  getDaysInMonth(year: number, month: number): number {
    return new Date(year, month, 0).getDate();
  }

  pad(num: number): string {
    return num < 10 ? '0' + num.toString() : num.toString();
  }

  formatDate(date: Date): string {
    return date.getFullYear() + '-' +
      this.pad(date.getMonth() + 1) + '-' +
      this.pad(date.getDate());
  }

  isToday(dateStr: string): boolean {
    let today: string = this.formatDate(new Date());
    return dateStr === today;
  }

  async loadEntries(date: string): Promise<void> {
    this.isLoading = true;
    try {
      let api: ApiService = ApiService.getInstance();
      let action: string = 'get_personal_history';
      if (this.currentVlogTab === 1) {
        action = 'get_authorized_vlogs';
      } else if (this.currentVlogTab === 2) {
        action = 'get_class_history';
      }
      let response: VlogListResponse = await api.request<VlogListResponse>(action, {
        date: date,
      });
      if (response.success) {
        this.entries = response.entries;
      }
    } catch (e) {
    } finally {
      this.isLoading = false;
    }
  }
}