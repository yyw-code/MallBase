import { reactive } from 'vue';

export interface LoginPageMetaState {
  loginSubtitle: string;
  loginTitle: string;
  pageDescription: string;
  pageTitle: string;
  sloganImage: string;
}

export const loginPageMetaState = reactive<LoginPageMetaState>({
  loginSubtitle: '',
  loginTitle: '',
  pageDescription: '',
  pageTitle: '',
  sloganImage: '',
});

export function updateLoginPageMeta(patch: Partial<LoginPageMetaState>): void {
  Object.assign(loginPageMetaState, patch);
}
