import { Constants } from './Constants';

export class Theme {
  static getBgColor(): string {
    return Constants.COLOR_BG;
  }

  static getTextColor(): string {
    return Constants.COLOR_TEXT;
  }

  static getAccentColor(): string {
    return Constants.COLOR_ACCENT;
  }

  static getSecAccentColor(): string {
    return Constants.COLOR_SEC_ACCENT;
  }

  static getCardBorderRadius(): string {
    return '12px';
  }

  static getWobblyBorder(): BorderOptions {
    return {
      width: 2,
      color: Constants.COLOR_BORDER,
      style: BorderStyle.Solid,
    };
  }

  static getHardShadow(): ShadowOptions {
    return {
      radius: 0,
      offsetX: 4,
      offsetY: 4,
      color: Constants.COLOR_BORDER,
      fill: false,
    };
  }
}