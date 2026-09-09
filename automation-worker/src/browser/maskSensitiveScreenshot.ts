import type { Page } from 'playwright';

/**
 * Narrow privacy mask for Wasim purchase/reconcile artifacts (C1.4A/B).
 * Temporarily blanks player-id inputs and hides supplier-balance chrome
 * before PNG capture, then restores. Not a general redaction platform.
 */
export async function withMaskedSensitiveWasimFields<T>(
  page: Page,
  label: string,
  capture: () => Promise<T>,
): Promise<T> {
  const shouldMaskPlayer = /pre[_-]?submit|player[_-]?id|price[_-]?check|product|purchase[_-]?response/i.test(label);
  const shouldMaskBalance = /pre[_-]?submit|player[_-]?id|price[_-]?check|product|purchase[_-]?response|login|orders[_-]?page|reconcile_/i.test(label);

  if (!shouldMaskPlayer && !shouldMaskBalance) {
    return capture();
  }

  const maskState = await page.evaluate(({ maskPlayer, maskBalance }) => {
    const playerInputs = maskPlayer
      ? Array.from(
          document.querySelectorAll<HTMLInputElement>(
            '#product-request-playrid, input[name="playerId"], input[placeholder="معرف اللاعب"]',
          ),
        )
      : [];

    const playerValues = playerInputs.map((input) => input.value);
    playerInputs.forEach((input) => {
      input.dataset.karmanPrivacyMask = '1';
      input.value = '••••••••';
    });

    const balanceNodes = maskBalance
      ? Array.from(
          document.querySelectorAll<HTMLElement>(
            [
              '#balancePlaceHolder',
              '#currencyPlaceHolder',
              '[id*="balance" i]',
              '[id*="Balance"]',
              '[class*="balance" i]',
              '[class*="Balance"]',
            ].join(', '),
          ),
        )
      : [];

    const balanceStyles = balanceNodes.map((node) => ({
      visibility: node.style.visibility,
      opacity: node.style.opacity,
      filter: node.style.filter,
    }));

    balanceNodes.forEach((node) => {
      node.dataset.karmanBalanceMask = '1';
      node.style.visibility = 'hidden';
      node.style.opacity = '0';
      node.style.filter = 'blur(8px)';
    });

    const style = document.createElement('style');
    style.id = 'karman-privacy-mask-style';
    style.textContent = `
      [data-karman-balance-mask="1"],
      #balancePlaceHolder,
      #currencyPlaceHolder {
        visibility: hidden !important;
        opacity: 0 !important;
      }
    `;
    if (maskBalance) {
      document.documentElement.appendChild(style);
    }

    (window as unknown as {
      __karmanMaskedPlayerValues?: string[];
      __karmanBalanceStyles?: Array<{ visibility: string; opacity: string; filter: string }>;
    }).__karmanMaskedPlayerValues = playerValues;
    (window as unknown as {
      __karmanBalanceStyles?: Array<{ visibility: string; opacity: string; filter: string }>;
    }).__karmanBalanceStyles = balanceStyles;

    return {
      playerCount: playerValues.length,
      balanceCount: balanceNodes.length,
    };
  }, { maskPlayer: shouldMaskPlayer, maskBalance: shouldMaskBalance }).catch(() => ({
    playerCount: 0,
    balanceCount: 0,
  }));

  try {
    return await capture();
  } finally {
    if (maskState.playerCount > 0 || maskState.balanceCount > 0 || shouldMaskBalance) {
      await page.evaluate(() => {
        const values = (window as unknown as { __karmanMaskedPlayerValues?: string[] }).__karmanMaskedPlayerValues ?? [];
        const inputs = Array.from(
          document.querySelectorAll<HTMLInputElement>(
            '#product-request-playrid, input[name="playerId"], input[placeholder="معرف اللاعب"]',
          ),
        );

        inputs.forEach((input, index) => {
          if (values[index] !== undefined) {
            input.value = values[index]!;
          }
          delete input.dataset.karmanPrivacyMask;
        });

        const balanceStyles = (window as unknown as {
          __karmanBalanceStyles?: Array<{ visibility: string; opacity: string; filter: string }>;
        }).__karmanBalanceStyles ?? [];
        const balanceNodes = Array.from(
          document.querySelectorAll<HTMLElement>('[data-karman-balance-mask="1"]'),
        );

        balanceNodes.forEach((node, index) => {
          const prior = balanceStyles[index];
          if (prior) {
            node.style.visibility = prior.visibility;
            node.style.opacity = prior.opacity;
            node.style.filter = prior.filter;
          } else {
            node.style.removeProperty('visibility');
            node.style.removeProperty('opacity');
            node.style.removeProperty('filter');
          }
          delete node.dataset.karmanBalanceMask;
        });

        document.getElementById('karman-privacy-mask-style')?.remove();
        delete (window as unknown as { __karmanMaskedPlayerValues?: unknown }).__karmanMaskedPlayerValues;
        delete (window as unknown as { __karmanBalanceStyles?: unknown }).__karmanBalanceStyles;
      }).catch(() => undefined);
    }
  }
}
