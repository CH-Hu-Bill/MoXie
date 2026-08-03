import preferences from '@ohos.data.preferences';
import { Constants } from '../common/Constants';
import { UserData } from '../model/UserData';

export class AuthService {
  private static instance: AuthService;
  private preferences_: preferences.Preferences | null = null;
  private currentUser: UserData | null = null;

  static getInstance(): AuthService {
    if (AuthService.instance === null || AuthService.instance === undefined) {
      AuthService.instance = new AuthService();
    }
    return AuthService.instance;
  }

  async init(context: Context): Promise<void> {
    this.preferences_ = await preferences.getPreferences(context, 'moxie_auth');
    let token: string = await this.getPreference(Constants.TOKEN_KEY, '');
    if (token.length > 0) {
      let userJson: string = await this.getPreference(Constants.USER_KEY, '');
      if (userJson.length > 0) {
        this.currentUser = JSON.parse(userJson) as UserData;
      }
    }
  }

  isLoggedIn(): boolean {
    return this.currentUser !== null;
  }

  getUser(): UserData | null {
    return this.currentUser;
  }

  async saveUser(user: UserData): Promise<void> {
    this.currentUser = user;
    await this.setPreference(Constants.TOKEN_KEY, user.token);
    await this.setPreference(Constants.USER_KEY, JSON.stringify(user));
  }

  async logout(): Promise<void> {
    this.currentUser = null;
    if (this.preferences_ !== null) {
      await this.preferences_.delete(Constants.TOKEN_KEY);
      await this.preferences_.delete(Constants.USER_KEY);
      await this.preferences_.delete(Constants.CLASS_KEY);
      await this.preferences_.flush();
    }
  }

  async getClassId(): Promise<number> {
    if (this.preferences_ === null) {
      return 0;
    }
    let classId: number = await this.getPreference(Constants.CLASS_KEY, 0);
    return classId;
  }

  async saveClassId(classId: number): Promise<void> {
    await this.setPreference(Constants.CLASS_KEY, classId);
  }

  private async getPreference<T>(key: string, defaultValue: T): Promise<T> {
    if (this.preferences_ === null) {
      return defaultValue;
    }
    return await this.preferences_.get(key, defaultValue) as T;
  }

  private async setPreference(key: string, value: string | number | boolean): Promise<void> {
    if (this.preferences_ === null) {
      return;
    }
    await this.preferences_.put(key, value);
    await this.preferences_.flush();
  }
}