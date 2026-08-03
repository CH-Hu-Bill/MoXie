import UIAbility from '@ohos.app.ability.UIAbility';
import Window from '@ohos.window';
import hilog from '@ohos.hilog';
import { AuthService } from '../service/AuthService';

export default class EntryAbility extends UIAbility {
  onCreate(want: Want, launchParam: AbilityConstant.LaunchParam): void {
    hilog.info(0x0000, 'EntryAbility', 'onCreate');
  }

  onDestroy(): void {
    hilog.info(0x0000, 'EntryAbility', 'onDestroy');
  }

  onWindowStageCreate(windowStage: Window.WindowStage): void {
    let authService: AuthService = AuthService.getInstance();
    authService.init(this.context);

    windowStage.loadContent('pages/Index', (err, data) => {
      if (err.code) {
        hilog.error(0x0000, 'EntryAbility', 'Failed to load content. Cause: %{public}s', JSON.stringify(err));
        return;
      }
      hilog.info(0x0000, 'EntryAbility', 'Succeeded in loading content. Data: %{public}s', JSON.stringify(data));
    });
  }

  onWindowStageDestroy(): void {
    hilog.info(0x0000, 'EntryAbility', 'onWindowStageDestroy');
  }

  onForeground(): void {
    hilog.info(0x0000, 'EntryAbility', 'onForeground');
  }

  onBackground(): void {
    hilog.info(0x0000, 'EntryAbility', 'onBackground');
  }
}