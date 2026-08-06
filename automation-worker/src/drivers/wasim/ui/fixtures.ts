/**
 * Sanitized minimal DOM fixtures for wasim-ui-v1 contracts.
 * No credentials, cookies, player IDs, or balances.
 */

export const FIXTURE_LOGIN_V1 = `
<html><body>
<form>
  <input id="Input_Email" name="Input.Email" placeholder="name@example.com" />
  <input id="Input_Password" name="Input.Password" placeholder="password" type="password" />
  <button type="submit">دخول</button>
</form>
</body></html>`;

export const FIXTURE_PRODUCT_V1 = `
<html><body>
  <input id="product-request-playrid" name="playerId" placeholder="معرف اللاعب" />
  <input id="product-request-quantity" name="quantity" placeholder="الكمية" />
  <input id="product-request-TotalPrice" name="TotalPrice" placeholder="الاجمالي" value="12.50" />
  <a id="product-request-buyid">إتمام الشراء</a>
</body></html>`;

export const FIXTURE_PRODUCT_AMBIGUOUS_SUBMIT = `
<html><body>
  <input id="product-request-playrid" name="playerId" />
  <input id="product-request-TotalPrice" name="TotalPrice" value="12.50" />
  <a id="product-request-buyid">إتمام الشراء</a>
  <a href="#">إتمام الشراء</a>
</body></html>`;

export const FIXTURE_PRODUCT_MISSING_FIELD = `
<html><body>
  <input id="product-request-TotalPrice" name="TotalPrice" value="12.50" />
  <a id="product-request-buyid">إتمام الشراء</a>
</body></html>`;

export const FIXTURE_ORDERS_V1 = `
<html><body>
  <button id="btn-Cancelled">Cancelled</button>
  <button id="btn-Completed">Completed</button>
  <button id="btn-new">New</button>
  <input id="startDate" />
  <button id="btn-Transaction">Reload</button>
  <table id="responsiveDataTable2"><tbody></tbody></table>
  <input aria-controls="responsiveDataTable2" />
</body></html>`;

export const FIXTURE_ORDERS_PARTIAL = `
<html><body>
  <button id="btn-new">New</button>
  <table id="responsiveDataTable2"><tbody></tbody></table>
</body></html>`;

export const FIXTURE_MAINTENANCE = `
<html><body data-wasim-maintenance="1">
  <h1>Under maintenance</h1>
</body></html>`;

export const FIXTURE_ACCESS_DENIED = `
<html><body>
  <h1>Access denied</h1>
  <p>غير مصرح</p>
</body></html>`;

export const FIXTURE_UNKNOWN = `
<html><body>
  <div class="spa-root">Welcome to a future Wasim UI</div>
</body></html>`;

export const FIXTURE_AMBIGUOUS = `
<html><body>
  <input id="Input_Email" name="Input.Email" />
  <input id="Input_Password" name="Input.Password" />
  <button>دخول</button>
  <input id="product-request-playrid" name="playerId" />
  <input id="product-request-TotalPrice" name="TotalPrice" />
  <a id="product-request-buyid">إتمام الشراء</a>
</body></html>`;

export const FIXTURE_SWAL_ACCEPTED = `
<div class="swal2-html-container">
  <p>معرف الطلب: <b>WS-1001</b></p>
  <p>حالة الطلب: <b>Processing_OK_wait</b></p>
</div>`;

export const FIXTURE_SWAL_REJECTED = `
<div class="swal2-html-container">
  <p>حالة الطلب: <b>pending</b></p>
  <p>الرد: <b>رصيدك غير كافي</b></p>
</div>`;
