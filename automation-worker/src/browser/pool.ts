import { chromium, type Browser, type BrowserContext } from 'playwright';
import {
  ensureSessionDir,
  ensureSessionMatchesCredentials,
  hasSessionState,
  sessionStatePath,
  writeSessionCredentialFingerprint,
} from './sessionStore.js';

type SessionCredentials = {
  username?: string;
  password?: string;
};

const sessionLocks = new Map<string, Promise<void>>();

let browser: Browser | null = null;

async function getBrowser(): Promise<Browser> {
  if (browser === null) {
    browser = await chromium.launch({
      headless: process.env.PLAYWRIGHT_HEADLESS !== 'false',
    });
  }

  return browser;
}

export async function withSessionLock<T>(
  sessionKey: string,
  callback: () => Promise<T>,
): Promise<T> {
  const previous = sessionLocks.get(sessionKey) ?? Promise.resolve();
  let release!: () => void;
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });

  sessionLocks.set(sessionKey, previous.then(() => gate));

  await previous;

  try {
    return await callback();
  } finally {
    release();
    if (sessionLocks.get(sessionKey) === gate) {
      sessionLocks.delete(sessionKey);
    }
  }
}

export async function withBrowserContext<T>(
  sessionKey: string,
  credentials: SessionCredentials | undefined,
  callback: (context: BrowserContext) => Promise<T>,
): Promise<T> {
  return withSessionLock(sessionKey, async () => {
    ensureSessionMatchesCredentials(sessionKey, credentials?.username, credentials?.password);
    ensureSessionDir(sessionKey);
    const b = await getBrowser();
    const storageState = hasSessionState(sessionKey) ? sessionStatePath(sessionKey) : undefined;

    const context = await b.newContext(storageState ? { storageState } : undefined);

    try {
      return await callback(context);
    } finally {
      await context.storageState({ path: sessionStatePath(sessionKey) });
      writeSessionCredentialFingerprint(sessionKey, credentials?.username, credentials?.password);
      await context.close();
    }
  });
}

export async function shutdownBrowserPool(): Promise<void> {
  if (browser !== null) {
    await browser.close();
    browser = null;
  }
}
