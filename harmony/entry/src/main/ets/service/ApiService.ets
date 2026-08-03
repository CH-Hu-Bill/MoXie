import http from '@ohos.net.http';
import { Constants } from '../common/Constants';

interface RequestParams {
  [key: string]: string;
}

export class ApiService {
  private static instance: ApiService;
  private baseUrl: string = Constants.BASE_URL;
  private token: string = '';

  static getInstance(): ApiService {
    if (ApiService.instance === null || ApiService.instance === undefined) {
      ApiService.instance = new ApiService();
    }
    return ApiService.instance;
  }

  setBaseUrl(url: string): void {
    this.baseUrl = url;
  }

  setToken(token: string): void {
    this.token = token;
  }

  getToken(): string {
    return this.token;
  }

  async request<T>(action: string, params: RequestParams = {}): Promise<T> {
    let url: string = this.baseUrl + Constants.API_PATH;
    let httpRequest: http.HttpRequest = http.createHttp();

    try {
      let formData: string = 'action=' + action;
      for (let key in params) {
        if (params[key] !== undefined && params[key] !== null) {
          formData = formData + '&' + key + '=' + encodeURIComponent(params[key]);
        }
      }

      let headers: RequestParams = {
        'Content-Type': 'application/x-www-form-urlencoded',
      };

      if (this.token.length > 0) {
        headers['Authorization'] = 'Bearer ' + this.token;
      }

      let response: http.HttpResponse = await httpRequest.request(url, {
        method: http.RequestMethod.POST,
        header: headers,
        extraData: formData,
        connectTimeout: Constants.TIMEOUT,
        readTimeout: Constants.TIMEOUT,
        expectDataType: http.HttpDataType.OBJECT,
      });

      let resultStr: string = JSON.stringify(response.result);
      let parsed: T = JSON.parse(resultStr) as T;

      return parsed;
    } catch (e) {
      throw new Error('Network request failed: ' + JSON.stringify(e));
    } finally {
      httpRequest.destroy();
    }
  }

  async uploadFile<T>(action: string, filePath: string, extraParams: RequestParams = {}): Promise<T> {
    let url: string = this.baseUrl + Constants.API_PATH;
    let httpRequest: http.HttpRequest = http.createHttp();

    try {
      let headers: RequestParams = {};

      if (this.token.length > 0) {
        headers['Authorization'] = 'Bearer ' + this.token;
      }

      let response: http.HttpResponse = await httpRequest.request(url, {
        method: http.RequestMethod.POST,
        header: headers,
        extraData: {
          action: action,
          image: filePath,
          name: 'image',
        },
        connectTimeout: Constants.TIMEOUT,
        readTimeout: 60000,
        expectDataType: http.HttpDataType.OBJECT,
      });

      let resultStr: string = JSON.stringify(response.result);
      let parsed: T = JSON.parse(resultStr) as T;

      return parsed;
    } catch (e) {
      throw new Error('Upload failed: ' + JSON.stringify(e));
    } finally {
      httpRequest.destroy();
    }
  }
}