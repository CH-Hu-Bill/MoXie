import media from '@ohos.multimedia.media';
import http from '@ohos.net.http';
import fs from '@ohos.file.fs';

export class TtsService {
  private static instance: TtsService;
  private audioPlayer: media.AudioPlayer | null = null;
  private httpClient: http.HttpRequest | null = null;

  static getInstance(): TtsService {
    if (TtsService.instance === null || TtsService.instance === undefined) {
      TtsService.instance = new TtsService();
    }
    return TtsService.instance;
  }

  getAudioUrl(word: string): string {
    return 'https://dict.youdao.com/dictvoice?audio=' + encodeURIComponent(word) + '&type=0';
  }

  async playWord(word: string): Promise<void> {
    try {
      if (this.audioPlayer !== null) {
        await this.audioPlayer.stop();
        await this.audioPlayer.release();
        this.audioPlayer = null;
      }

      this.audioPlayer = await media.createAudioPlayer();
      let url: string = this.getAudioUrl(word);

      this.audioPlayer.src = url;
      this.audioPlayer.audioInterruptMode = media.InterruptMode.INDEPENDENT_MODE;
      await this.audioPlayer.prepare();
      await this.audioPlayer.play();
    } catch (e) {
      // Silent audio or failure - no action needed
    }
  }

  async release(): Promise<void> {
    if (this.audioPlayer !== null) {
      await this.audioPlayer.release();
      this.audioPlayer = null;
    }
  }
}