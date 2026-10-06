// "/api/persons?x=1" -> "api.php?p=/persons&x=1" (the PHP API next to this page)
// Today in the Jalali calendar, Latin digits: "1405/07/10"
const JDATE = (() => {
  try {
    const parts = new Intl.DateTimeFormat("fa-IR-u-ca-persian-nu-latn", { year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(new Date());
    const v = t => (parts.find(x => x.type === t) || {}).value;
    return v("year") + "/" + v("month") + "/" + v("day");
  } catch (e) { return "1405/01/01"; }
})();

const API = (path) => {
  if (path.startsWith("http")) return path;
  const [p, q] = path.replace(/^\/api/, "").split("?");
  return "api.php?p=" + encodeURIComponent(p) + (q ? "&" + q : "");
};

function accountingApp() {
  return {
    isLoggedIn: false,
    menuOpen: false,
    embedded: (() => { try { return window.self !== window.top || /[?&]embed/.test(location.search); } catch (e) { return true; } })(),
    token: localStorage.getItem("acc_token") || "",
    currentPage: "dashboard",
    loginForm: { username: "admin", password: "" },
    pwForm: { old_password: "", new_password: "" },
    sms: { tab: "single", settings: { sms_provider: "test" }, templates: [], patterns: [], log: [], counts: {}, credit: null, creditUnit: "",
      logStatus: "", logQ: "", tplForm: { title: "", body: "" }, patForm: { title: "", code: "", params: "" },
      single: { person_id: "", mobile: "", mode: "text", text: "", template_id: "", pattern_id: "", values: {} },
      group: { target: "all", group: "", min_balance: 0, ids: [], numbers: "", mode: "text", text: "", template_id: "", pattern_id: "", values: {} } },
    brands: [], departments: [],
    companies: [], companyId: Number(localStorage.getItem("acc_company")) || 1, companyForm: { name: "", copy: false },
    bank: { rows: [], status: "", direction: "", days: 30, total_in: 0, total_out: 0, pending: 0, meta: { categories: [], wallets: [], parties: [] }, edit: null, form: {} },
    taxKeys: { has_key: false, has_certificate: false, public_key: "", env: "main" },
    taxForm: { private_key: "", certificate: "", env: "main" },
    bankLink: { enabled: false, wallets: [], imported: 0, last_error: null },
    more: { tab: "phonebook", rows: [], q: "", form: {}, csv: "", importKind: "persons", result: "", labelIds: [], copies: 1 },
    rep: { tab: "trade", from: "", to: "", group: "sale", method: "avg", days: 30, cover: 30, account: "", year: "", season: 1, data: null, open: null },
    user: { name: "مدیر سیستم", role: "admin", permissions: ["*"] },
    company: { name: "شرکت", national_id: "", economic_code: "", vat_rate: 10, webhook_enabled: false, webhook_url: "", webhook_secret: "", api_key: "" },
    todayJalali: new Date().toLocaleDateString("fa-IR"),
    persons: [],
    products: [],
    salesInvoices: [],
    purchaseInvoices: [],
    journals: [],
    users: [],
    logs: [],
    accounts: [],
    txns: [],
    cheques: [],
    showTxnModal: false,
    showChequeModal: false,
    showAccountModal: false,
    txnForm: { kind: "receive", account_id: "", to_account_id: "", person_id: "", invoice_id: "", amount: 0, date: JDATE, description: "" },
    chequeForm: { number: "", direction: "received", person_id: "", amount: 0, due_date: "", bank_name: "" },
    accountForm: { name: "", kind: "cash", account_no: "", balance: 0 },
    taxInvoices: [],
    taxReport: { vat_sale:0, vat_purchase:0, vat_payable:0, missing_buyer_id:0 },
    attachments: [],
    attachTarget: { object_type: "invoice", object_id: 0 },
    branches: [],
    currencies: [],
    statements: [],
    showStmtModal: false,
    branchForm: { name: "", city: "" },
    currencyForm: { code: "USD", name: "دلار", rate: 0 },
    stmtForm: { account_id: "", date: JDATE, amount: 0, description: "" },
    serials: [],
    stockCounts: [],
    showSerialModal: false,
    showCountModal: false,
    serialForm: { product_id: "", warehouse_id: "", serial: "", lot: "", expiry: "" },
    countForm: { warehouse_id: "", date: JDATE, items: [{product_id:"", counted_qty:0}] },
    warehouses: [],
    stockRows: [],
    whDocs: [],
    stockWarehouse: "",
    showWhModal: false,
    showWhDocModal: false,
    whForm: { name: "", is_default: false },
    whDocForm: { kind: "receipt", warehouse_id: "", to_warehouse_id: "", date: JDATE, items: [{product_id:"", qty:1}] },
    coa: [],
    fiscal: { name: "1405", locked: false },
    trialRows: [],
    showCoaModal: false,
    coaForm: { code: "", name: "", level: "moein", nature: "debit", parent_code: "" },
    stats: { sales: 0, purchases: 0, receivables: 0, payables: 0, stock_value: 0, cash: 0 },
    lowStock: [],
    personFilter: "all",
    productSearch: "",
    showPersonModal: false,
    showProductModal: false,
    showInvoiceModal: false,
    showJournalModal: false,
    showUserModal: false,
    editingPerson: null,
    editingProduct: null,
    invoiceType: "sale",
    editingInvoiceId: null,
    personForm: { name: "", type: "customer", mobile: "", national_id: "", credit_limit: 0, legal_type: "real", address: "", groups: "" },
    productForm: { name: "", code: "", unit: "عدد", sale_price: 0, buy_price: 0, stock: 0, reorder_point: 5, max_stock: 0, barcode: "", group_name: "", track_serial: false, track_lot: false, kind: "goods", brand_id: "", tax_code: "" },
    invoiceForm: { person_id: "", date: JDATE, items: [{ product_id: "", qty: 1, price: 0, unit: "primary" }], discount: 0, discount_percent: 0, freight: 0, customs: 0, other_cost: 0, subtotal: 0, tax: 0, total: 0, due_date: "", marketer_id: "", department_id: "", note: "", no_vat: false },
    journalForm: { description: "", date: JDATE, lines: [{ account_id: "", debit: 0, credit: 0 }] },
    userForm: { username: "", password: "", full_name: "", role: "seller" },
    reportContent: null,
    reportTitle: "",
    lastBackupAt: null,
    menuItems: [
      { id: "dashboard", title: "داشبورد", icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>' },
      { id: "persons", title: "اشخاص و طرف‌حساب", icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>' },
      { id: "tax", title: "مالیات و مؤدیان", icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>' },
      { id: "warehouse", title: "انبار پیشرفته", icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>' },
      { id: "products", title: "کالا و انبار", icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>' },
      { id: "sales", title: "فروش", icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>' },
      { id: "purchases", title: "خرید", icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>' },
      { id: "treasury", title: "خزانه‌داری", icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>' },
      { id: "accounting", title: "حسابداری", icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>' },
      { id: "reports", title: "گزارش‌ها", icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>' },
      { id: "bank", title: "تراکنش‌های بانک", icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7l-4 4 4 4M3 11h13M17 17l4-4-4-4M21 13H8"/></svg>' },
      { id: "more", title: "امکانات بیشتر", icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"/></svg>' },
      { id: "mreports", title: "گزارش‌های مدیریتی", icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3v18M5 9v12M17 13v8"/></svg>' },
      { id: "sms", title: "پنل پیامک", icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>' },
      { id: "settings", title: "تنظیمات", icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>' }
    ],
    get customers() { return this.persons.filter(p => p.type !== "supplier"); },
    get suppliers() { return this.persons.filter(p => p.type !== "customer"); },
    get marketers() { return this.persons.filter(p => p.type === "marketer"); },
    get moeinAccounts() { return this.coa.filter(a => a.level === "moein" && a.code !== "1101"); },
    get filteredPersons() {
      if (this.personFilter === "all") return this.persons;
      return this.persons.filter(p => p.type === this.personFilter);
    },
    get filteredProducts() {
      if (!this.productSearch) return this.products;
      const q = this.productSearch.toLowerCase();
      return this.products.filter(p => (p.name || "").toLowerCase().includes(q) || String(p.code || "").includes(q));
    },
    get lowStockProducts() { return this.lowStock; },

    /** Address for links opened outside fetch (print, CSV, files): carries the login token. */
    link(path) {
      return API(path) + "&token=" + encodeURIComponent(this.token) + "&company=" + (this.companyId || 1);
    },

    get personGroups() {
      const g = new Set();
      this.persons.forEach(p => (p.groups || "").split(",").filter(Boolean).forEach(x => g.add(x)));
      return [...g];
    },
    async smsLoad() {
      try {
        const [t, p] = await Promise.all([this.req("/api/sms/templates"), this.req("/api/sms/patterns")]);
        this.sms.templates = t; this.sms.patterns = p;
        if (this.user.role === "admin") this.sms.settings = await this.req("/api/sms/settings");
        this.smsLoadLog();
      } catch (e) { alert(e.message); }
    },
    async smsLoadLog() {
      try {
        const q = "?status=" + encodeURIComponent(this.sms.logStatus) + "&q=" + encodeURIComponent(this.sms.logQ);
        const r = await this.req("/api/sms/log" + q);
        this.sms.log = r.rows; this.sms.counts = r.counts;
      } catch (e) {}
    },
    async smsLoadCredit() {
      try { const r = await this.req("/api/sms/credit"); this.sms.credit = r.credit; this.sms.creditUnit = r.unit; }
      catch (e) { alert(e.message); }
    },
    smsPatternParams(id) {
      const p = this.sms.patterns.find(x => x.id == id);
      return p ? p.params : [];
    },
    /** Characters and SMS parts (Persian: 70 per part, 67 when split). */
    smsCount(t) {
      const n = (t || "").length;
      const fa = /[^\x00-\x7F]/.test(t || "");
      const one = fa ? 70 : 160, multi = fa ? 67 : 153;
      return n + " حرف · " + (n <= one ? 1 : Math.ceil(n / multi)) + " پیامک";
    },
    smsPayload(f) {
      const b = {};
      if (f.mode === "template") b.template_id = Number(f.template_id) || 0;
      else if (f.mode === "pattern") { b.pattern_id = Number(f.pattern_id) || 0; b.values = f.values; }
      else b.text = f.text;
      return b;
    },
    async smsSendSingle() {
      const f = this.sms.single;
      try {
        await this.req("/api/sms/send", { method: "POST", body: JSON.stringify({ ...this.smsPayload(f), mobile: f.mobile, person_id: Number(f.person_id) || null }) });
        alert("پیامک فرستاده شد");
        this.sms.single = { person_id: "", mobile: "", mode: f.mode, text: "", template_id: f.template_id, pattern_id: f.pattern_id, values: {} };
        this.smsLoadLog();
      } catch (e) { alert(e.message); }
    },
    async smsSendGroup() {
      const f = this.sms.group;
      if (!confirm("پیامک برای گیرندگان انتخاب‌شده فرستاده شود؟")) return;
      try {
        const r = await this.req("/api/sms/group", { method: "POST", body: JSON.stringify({ ...this.smsPayload(f), target: f.target, group: f.group,
          min_balance: f.min_balance, ids: f.ids, numbers: f.numbers }) });
        alert(r.queued + " پیامک در صف ارسال قرار گرفت" + (r.invalid ? "؛ " + r.invalid + " شماره نامعتبر بود" : ""));
        this.sms.tab = "log"; this.smsLoadLog();
      } catch (e) { alert(e.message); }
    },
    async smsSaveItem(kind) {
      const f = kind === "templates" ? this.sms.tplForm : this.sms.patForm;
      try {
        await this.req("/api/sms/" + kind + (f.id ? "/" + f.id : ""), { method: f.id ? "PUT" : "POST", body: JSON.stringify(f) });
        if (kind === "templates") this.sms.tplForm = { title: "", body: "" }; else this.sms.patForm = { title: "", code: "", params: "" };
        this.smsLoad();
      } catch (e) { alert(e.message); }
    },
    async smsDeleteItem(kind, id) {
      if (!confirm("حذف شود؟")) return;
      try { await this.req("/api/sms/" + kind + "/" + id, { method: "DELETE" }); this.smsLoad(); } catch (e) { alert(e.message); }
    },
    async smsSaveSettings() {
      try { await this.req("/api/sms/settings", { method: "PUT", body: JSON.stringify(this.sms.settings) }); alert("ذخیره شد"); this.smsLoad(); }
      catch (e) { alert(e.message); }
    },
    async smsProcess() {
      try { await this.req("/api/sms/process", { method: "POST" }); this.smsLoadLog(); } catch (e) { alert(e.message); }
    },
    async smsRetry(r) {
      try { await this.req("/api/sms/retry/" + r.id, { method: "POST" }); this.smsLoadLog(); } catch (e) { alert(e.message); }
    },

    /* ---------- امکانات بیشتر ---------- */
    moreTabs: [["phonebook", "دفترچه تلفن"], ["guarantees", "اسناد ضمانتی"], ["loans", "وام و قرض"], ["advances", "پیش‌دریافت / پیش‌پرداخت"],
      ["expense", "انواع هزینه و درآمد"], ["cheque-books", "دسته چک"], ["production", "تولید"], ["brands", "برندها و بخش‌ها"],
      ["labels", "چاپ بارکد"], ["import", "ورود لیست از اکسل"], ["tools", "ابزار و بستن سال"]],
    moreForms: {
      phonebook: () => ({ name: "", phones: "", note: "" }),
      guarantees: () => ({ direction: "received", person_id: "", kind: "سفته", number: "", amount: 0, date: JDATE, due_date: "", status: "active", description: "" }),
      loans: () => ({ direction: "received", person_id: "", account_id: "", amount: 0, interest_total: 0, installments: 12, start_date: "", interval_months: 1, description: "" }),
      advances: () => ({ kind: "prereceive", person_id: "", account_id: "", amount: 0, date: JDATE, description: "" }),
      expense: () => ({ kind: "expense", name: "" }),
      "cheque-books": () => ({ account_id: "", bank_name: "", serial_from: "", serial_to: "" }),
      production: () => ({ product_id: "", name: "", qty_out: 1, extra_cost: 0, items: [{ product_id: "", qty: 1 }], run_bom: "", run_qty: 1, warehouse_id: "" }),
      brands: () => ({ brand: "", department: "" }),
    },
    rowsFor(t) { return this.more.tab === t && Array.isArray(this.more.rows) ? this.more.rows : []; },
    moreSetTab(t) { this.more.rows = []; this.more.tab = t; this.more.form = (this.moreForms[t] || (() => ({})))(); this.more.result = ""; this.moreLoad(); },
    async moreLoad() {
      const t = this.more.tab;
      if (!this.more.form || !Object.keys(this.more.form).length) this.more.form = (this.moreForms[t] || (() => ({})))();
      const url = { phonebook: "/api/phonebook?q=" + encodeURIComponent(this.more.q), guarantees: "/api/guarantees", loans: "/api/loans", advances: "/api/advances",
        expense: "/api/expense-types", "cheque-books": "/api/cheque-books", production: "/api/boms" }[t];
      try {
        [this.brands, this.departments] = await Promise.all([this.req("/api/brands"), this.req("/api/departments")]);
        if (url) { const rows = await this.req(url); if (this.more.tab === t) this.more.rows = rows; }
        if (t === "production") this.more.runs = await this.req("/api/productions");
      } catch (e) { this.more.rows = []; }
    },
    async morePost(path, body, method = "POST") {
      try {
        const r = await this.req("/api/" + path, { method, body: body ? JSON.stringify(body) : undefined });
        await this.moreLoad();
        return r || true;
      } catch (e) { alert(e.message); return null; }
    },
    async moreSave() {
      const t = this.more.tab, f = this.more.form;
      const num = o => { const b = { ...o }; ["person_id", "account_id", "product_id"].forEach(k => { b[k] = Number(b[k]) || null; }); return b; };
      const path = { phonebook: "phonebook", guarantees: "guarantees", loans: "loans", advances: "advances", expense: "expense-types", "cheque-books": "cheque-books" }[t];
      if (await this.morePost(path + (f.id ? "/" + f.id : ""), num(f), f.id ? "PUT" : "POST")) {
        this.more.form = this.moreForms[t]();
        if (["loans", "advances", "expense"].includes(t)) this.refreshAll();
      }
    },
    async moreDelete(path) { if (confirm("حذف شود؟")) await this.morePost(path, null, "DELETE"); },
    async payInstallment(i) {
      const acc = this.pick("از کدام حساب؟", this.accounts, x => x.name);
      if (!acc) return;
      if (await this.morePost("loans/installments/" + i.id + "/pay", { account_id: acc.id })) this.refreshAll();
    },
    async applyAdvance(a) {
      const amount = prompt("چه مبلغی از " + a.kind_label + " " + a.person_name + " تسویه شود؟", a.amount);
      if (amount === null) return;
      if (await this.morePost("advances/apply", { kind: a.kind, person_id: a.person_id, amount: Number(amount) })) this.refreshAll();
    },
    async saveBom() {
      const f = this.more.form;
      const body = { product_id: Number(f.product_id), name: f.name, qty_out: f.qty_out, extra_cost: f.extra_cost,
        items: f.items.filter(i => i.product_id).map(i => ({ product_id: Number(i.product_id), qty: i.qty })) };
      if (await this.morePost("boms" + (f.id ? "/" + f.id : ""), body, f.id ? "PUT" : "POST")) this.more.form = this.moreForms.production();
    },
    editBom(b) { this.more.form = { ...this.moreForms.production(), id: b.id, product_id: b.product_id, name: b.name, qty_out: b.qty_out, extra_cost: b.extra_cost, items: b.items.map(i => ({ ...i })) }; },
    async runProduction() {
      const f = this.more.form;
      const r = await this.morePost("productions", { bom_id: Number(f.run_bom), qty: Number(f.run_qty), warehouse_id: Number(f.warehouse_id) || null });
      if (r) { alert("تولید " + r.number + " ثبت شد؛ بهای هر واحد " + this.formatNumber(Math.round(r.unit_cost))); this.refreshAll(); }
    },
    async deleteProduction(p) { if (confirm("تولید " + p.number + " حذف و موجودی‌ها برگردانده شود؟") && await this.morePost("productions/" + p.id, null, "DELETE")) this.refreshAll(); },
    async saveNamed(kind) {
      const name = kind === "brands" ? this.more.form.brand : this.more.form.department;
      if (await this.morePost(kind, { name })) this.more.form = this.moreForms.brands();
    },
    printLabels() {
      window.open(this.link("/api/labels?ids=" + this.more.labelIds.join(",") + "&copies=" + (this.more.copies || 1)), "_blank");
    },
    async readCsvFile(ev) {
      const f = ev.target.files[0];
      if (!f) return;
      const buf = await f.arrayBuffer();
      let text = new TextDecoder("utf-8").decode(buf);
      if (text.includes("\ufffd")) text = new TextDecoder("windows-1256").decode(buf);
      this.more.csv = text;
    },
    async runImport() {
      const r = await this.morePost("import/" + this.more.importKind, { csv: this.more.csv });
      if (r) { this.more.result = r.imported + " ردیف وارد شد، " + r.skipped + " ردیف تکراری/خالی رد شد"; this.refreshAll(); }
    },
    async runTool(t) {
      const msg = { repair: "مانده‌ها و موجودی‌ها از روی اسناد دوباره حساب شوند؟", vacuum: "دیتابیس فشرده شود؟",
        "close-year": "سال مالی بسته شود؟ سند اختتامیه صادر و سال بعد باز می‌شود. قبلش پشتیبان بگیر." }[t];
      if (!confirm(msg)) return;
      const r = await this.morePost("tools/" + t);
      if (!r) return;
      if (t === "repair") this.more.result = r.fixed.length ? "اصلاح شد: " + r.fixed.join("، ") : "همه چیز سالم بود" + (r.unbalanced_journals ? " — " + r.unbalanced_journals + " سند تراز نیست" : "");
      if (t === "vacuum") this.more.result = "حجم از " + Math.round(r.before / 1024) + " به " + Math.round(r.after / 1024) + " کیلوبایت رسید";
      if (t === "close-year") this.more.result = "سال " + r.closed + " بسته شد؛ سال جاری: " + r.year;
      this.refreshAll();
    },

    /* ---------- گزارش‌های مدیریتی ---------- */
    repTabs: [["trade", "فروش و خرید دوره"], ["profit", "سود کالاها"], ["departments", "سود بخش‌ها"], ["marketers", "بازاریاب‌ها"],
      ["due", "حساب سررسیدار"], ["accounts", "خلاصه حساب‌ها"], ["cash", "صورتحساب بانک/صندوق"], ["operations", "ریز عملیات"],
      ["order", "برآورد سفارش"], ["unused", "کالاهای بدون گردش"], ["ttms", "معاملات فصلی"]],
    repSetTab(t) { this.rep.tab = t; this.rep.data = null; this.repLoad(); },
    repQuery() {
      const r = this.rep, q = new URLSearchParams();
      if (r.from) q.set("from", r.from);
      if (r.to) q.set("to", r.to);
      if (r.tab === "trade") q.set("group", r.group);
      if (r.tab === "profit") q.set("method", r.method);
      if (r.tab === "order") { q.set("days", r.days); q.set("cover", r.cover); }
      if (r.tab === "unused") q.set("days", r.days);
      return q.toString();
    },
    async repLoad() {
      const r = this.rep;
      const path = { trade: "reports/trade", profit: "reports/profit", departments: "reports/departments", marketers: "reports/marketers",
        due: "reports/due-invoices", accounts: "reports/accounts", cash: r.account ? "reports/cash/" + r.account : "", operations: "reports/operations",
        order: "reports/order-estimate", unused: "reports/unused" }[r.tab];
      if (!path) { r.data = null; return; }
      try { r.data = await this.req("/api/" + path + "?" + this.repQuery()); } catch (e) { alert(e.message); r.data = null; }
    },
    get repRows() {
      const d = this.rep.data;
      if (!d) return [];
      if (Array.isArray(d)) return d;
      if (this.rep.tab === "trade") return d.products;
      return d.rows || d.products || [];
    },
    get repCols() {
      return {
        trade: [["name", "کالا"], ["qty", "تعداد", "n"], ["amount", "مبلغ", "n"], ["cost", "بهای تمام‌شده", "n"], ["profit", "سود", "n"]],
        profit: [["name", "کالا"], ["qty", "تعداد", "n"], ["revenue", "فروش", "n"], ["cost", "بهای تمام‌شده", "n"], ["profit", "سود", "n"], ["margin", "حاشیه ٪"]],
        departments: [["name", "بخش"], ["count", "فاکتور"], ["revenue", "فروش", "n"], ["cost", "بهای تمام‌شده", "n"], ["profit", "سود", "n"]],
        marketers: [["name", "بازاریاب"], ["rate", "درصد"], ["invoices", "فاکتور"], ["sales", "فروش", "n"], ["commission", "کمیسیون", "n"]],
        due: [["number", "فاکتور"], ["person_name", "طرف حساب"], ["mobile", "موبایل"], ["date", "تاریخ"], ["due_date", "سررسید"], ["total", "مبلغ", "n"], ["paid", "دریافت/پرداخت", "n"], ["remaining", "مانده", "n"], ["days", "روز گذشته"]],
        accounts: [["code", "کد"], ["name", "حساب"], ["debit", "بدهکار", "n"], ["credit", "بستانکار", "n"], ["balance", "مانده", "n"]],
        cash: [["date", "تاریخ"], ["number", "سند"], ["description", "شرح"], ["debit", "واریز", "n"], ["credit", "برداشت", "n"], ["balance", "مانده", "n"]],
        operations: [["number", "سند"], ["date", "تاریخ"], ["description", "شرح"], ["amount", "مبلغ", "n"], ["source", "نوع"], ["status", "وضعیت"]],
        order: [["name", "کالا"], ["stock", "موجودی"], ["sold", "فروش دوره"], ["daily", "میانگین روزانه"], ["days_left", "روز تا اتمام"], ["suggest", "پیشنهاد خرید"]],
        unused: [["name", "کالا"], ["stock", "موجودی"], ["last_move", "آخرین گردش"], ["value", "ارزش", "n"]],
      }[this.rep.tab] || [];
    },
    repCell(row, c) {
      const v = row[c[0]];
      if (v === null || v === undefined) return "-";
      return c[2] === "n" ? this.formatNumber(Math.round(v)) : v;
    },
    async bookCommission(m) {
      if (!confirm("کمیسیون " + this.formatNumber(m.commission) + " ریال به حساب " + m.name + " ثبت شود؟")) return;
      try { await this.req("/api/reports/marketers/" + m.id + "/commission?" + this.repQuery(), { method: "POST" }); this.refreshAll(); alert("ثبت شد"); }
      catch (e) { alert(e.message); }
    },
    ttmsLink() { return this.link("/api/reports/ttms?year=" + (this.rep.year || JDATE.slice(0, 4)) + "&season=" + this.rep.season + "&kind=" + this.rep.group); },
    async deleteTxn(t) {
      if (!confirm("دریافت/پرداخت " + t.number + " حذف و سندش برگردانده شود؟")) return;
      try { await this.req("/api/treasury/" + t.id, { method: "DELETE" }); await this.refreshAll(); } catch (e) { alert(e.message); }
    },

    async bankLoad() {
      const b = this.bank;
      try {
        const q = "?status=" + b.status + "&direction=" + b.direction + "&days=" + b.days;
        const [r, m] = await Promise.all([this.req("/api/bank/transactions" + q), this.req("/api/bank/meta")]);
        Object.assign(b, { rows: r.rows, total_in: r.total_in, total_out: r.total_out, pending: r.pending, meta: m });
      } catch (e) { alert(e.message); }
    },
    bankEdit(t) {
      this.bank.edit = this.bank.edit === t.id ? null : t.id;
      this.bank.form = { description: t.description || "", party: t.party || "", category_id: t.category_id || "", note: t.note || "", counter_wallet_id: t.counter_wallet_id || "" };
    },
    bankCats(t) { return this.bank.meta.categories.filter(c => c.direction === "both" || c.direction === t.direction); },
    bankCatKind(id) { return (this.bank.meta.categories.find(c => c.id == id) || {}).kind || ""; },
    async bankAct(t, act) {
      try {
        const body = act === "confirm" ? { ...this.bank.form, category_id: Number(this.bank.form.category_id) || 0, counter_wallet_id: Number(this.bank.form.counter_wallet_id) || null } : {};
        await this.req("/api/bank/transactions/" + t.id + "/" + act, { method: "POST", body: JSON.stringify(body) });
        this.bank.edit = null;
        await this.bankLoad();
      } catch (e) { alert(e.message); }
    },
    toman(r) { return this.formatNumber(Math.round(Math.abs(r) / 10)) + " تومان"; },

    async saveBankLink() {
      try {
        this.bankLink = await this.req("/api/bank-link", { method: "PUT", body: JSON.stringify({ enabled: this.bankLink.enabled,
          wallets: this.bankLink.wallets.map(w => ({ id: w.id, account_id: Number(w.account_id) || null })) }) });
        alert("ذخیره شد");
      } catch (e) { alert(e.message); }
    },
    async syncBankLink() {
      try {
        await this.saveBankLink();
        const r = await this.req("/api/bank-link/sync", { method: "POST" });
        alert(r.imported + " تراکنش ثبت شد" + (r.errors.length ? "\nخطاها:\n" + r.errors.join("\n") : ""));
        await this.refreshAll();
      } catch (e) { alert(e.message); }
    },

    async changePassword() {
      try {
        await this.req("/api/me/password", { method: "PUT", body: JSON.stringify(this.pwForm) });
        this.pwForm = { old_password: "", new_password: "" };
        alert("رمز عوض شد؛ دوباره وارد شو.");
        this.logout();
      } catch (e) { alert(e.message); }
    },

    kindLabel(k) {
      return { sale: "فروش", purchase: "خرید", sale_return: "برگشت از فروش", purchase_return: "برگشت از خرید", sale_proforma: "پیش‌فاکتور", purchase_proforma: "پیش‌فاکتور", sale_order: "سفارش", purchase_order: "سفارش" }[k] || k;
    },

    async req(path, opts = {}) {
      const headers = { "Content-Type": "application/json", ...(opts.headers || {}) };
      if (this.token) { headers.Authorization = "Bearer " + this.token; headers["X-Auth-Token"] = this.token; }
      headers["X-Company"] = String(this.companyId || 1);
      const res = await fetch(API(path), { ...opts, headers });
      if (res.status === 401) {
        this.logout();
        throw new Error("نیاز به ورود مجدد");
      }
      if (!res.ok) {
        let msg = "خطای سرور";
        try { const j = await res.json(); msg = j.detail || msg; } catch (e) {}
        throw new Error(typeof msg === "string" ? msg : JSON.stringify(msg));
      }
      const ct = res.headers.get("content-type") || "";
      if (ct.includes("application/json")) return res.json();
      return res;
    },

    async init() {
      // inside the phone app (same site): sign in with its app password, no second login
      if (!this.token) {
        let app = "";
        try { app = localStorage.getItem("ba_token") || ""; } catch (e) {}
        if (app) {
          try {
            const res = await fetch(API("/api/login/app"), { method: "POST", headers: { "X-App-Token": app } });
            if (res.ok) { const d = await res.json(); this.token = d.token; localStorage.setItem("acc_token", d.token); }
          } catch (e) {}
        }
      }
      if (this.token) {
        try {
          const me = await this.req("/api/me");
          this.user = { name: me.full_name || me.username, role: me.role, permissions: me.permissions || [] };
          this.isLoggedIn = true;
          await this.refreshAll();
        } catch (e) {
          this.isLoggedIn = false;
        }
      }
    },

    /** Permission check for the menu (the server checks again on every request). */
    can(perm) {
      const p = (this.user && this.user.permissions) || [];
      return p.includes("*") || p.includes(perm);
    },
    get visibleMenu() {
      const need = { dashboard: "dashboard", persons: "persons", tax: "tax", warehouse: "warehouse", products: "products", sales: "sales",
        purchases: "purchases", treasury: "treasury", accounting: "accounting", reports: "reports", sms: "sms", mreports: "reports", bank: "treasury" };
      return this.menuItems.filter(m => !need[m.id] || this.can(need[m.id]));
    },

    async refreshAll() {
      // each list on its own: a user without access to one section still gets the rest
      const get = (path, fallback) => this.req(path).catch(() => fallback);
      this.companies = await get("/api/companies", []);
      if (this.companies.length && !this.companies.some(c => c.id == this.companyId)) { this.companyId = 1; localStorage.setItem("acc_company", "1"); }
      const [dash, company, persons, products, sales, purchases, journals, accounts, txns, cheques, coa, fiscal, warehouses, stockRows, whDocs, taxInvoices, taxReport, serials, stockCounts, branches, currencies, statements] = await Promise.all([
        get("/api/dashboard", { low_stock: [] }),
        get("/api/company", this.company),
        get("/api/persons", []),
        get("/api/products", []),
        get("/api/invoices?group=sale", []),
        get("/api/invoices?group=purchase", []),
        get("/api/journals", []),
        get("/api/accounts", []),
        get("/api/treasury", []),
        get("/api/cheques", []),
        get("/api/coa", []),
        get("/api/fiscal", this.fiscal),
        get("/api/warehouses", []),
        get("/api/stock", []),
        get("/api/warehouse-docs", []),
        get("/api/tax-invoices", []),
        get("/api/tax-report", this.taxReport),
        get("/api/serials", []),
        get("/api/stock-counts", []),
        get("/api/branches", []),
        get("/api/currencies", []),
        get("/api/bank-statements", []),
      ]);
      this.stats = Object.assign({ sales: 0, purchases: 0, receivables: 0, payables: 0, stock_value: 0, cash: 0 }, dash);
      this.lowStock = dash.low_stock || [];
      this.company = company;
      this.persons = persons;
      this.products = products;
      this.salesInvoices = sales.map(i => ({ ...i, personId: i.person_id, settled: i.settled }));
      this.purchaseInvoices = purchases.map(i => ({ ...i, personId: i.person_id }));
      this.journals = journals;
      this.accounts = accounts;
      this.txns = txns;
      this.cheques = cheques;
      this.coa = coa;
      this.fiscal = fiscal;
      this.warehouses = warehouses;
      this.stockRows = stockRows;
      this.whDocs = whDocs;
      this.taxInvoices = taxInvoices;
      this.taxReport = taxReport;
      this.serials = serials;
      this.stockCounts = stockCounts;
      this.branches = branches;
      this.currencies = currencies;
      this.statements = statements;
      [this.brands, this.departments] = await Promise.all([get("/api/brands", []), get("/api/departments", [])]);
      if (this.user.role === "admin") { this.bankLink = await get("/api/bank-link", this.bankLink); this.taxKeys = await get("/api/tax/keys", this.taxKeys); this.taxForm.env = this.taxKeys.env; }
      if (this.currentPage === "sms") this.smsLoad();
      if (this.currentPage === "more") this.moreLoad();
      if (this.currentPage === "bank") this.bankLoad();
      if (this.currentPage === "mreports") this.repLoad();
    },

    async login() {
      try {
        const data = await this.req("/api/login", { method: "POST", body: JSON.stringify(this.loginForm) });
        this.token = data.token;
        localStorage.setItem("acc_token", data.token);
        this.user = { name: data.user.full_name || data.user.username, role: data.user.role, permissions: data.user.permissions || [] };
        this.isLoggedIn = true;
        await this.refreshAll();
      } catch (e) {
        alert(e.message);
      }
    },
    logout() {
      this.isLoggedIn = false;
      this.token = "";
      localStorage.removeItem("acc_token");
    },
    can(perm) {
      const p = this.user.permissions || [];
      return p.includes("*") || p.includes(perm);
    },
    getPageTitle() {
      const item = this.menuItems.find(m => m.id === this.currentPage);
      return item ? item.title : "";
    },
    formatNumber(n) {
      return new Intl.NumberFormat("fa-IR").format(Math.round(n || 0));
    },
    getPersonName(id) {
      const p = this.persons.find(x => x.id == id);
      return p ? p.name : "-";
    },
    openPersonModal() {
      this.editingPerson = null;
      this.personForm = { name: "", type: "customer", mobile: "", phone2: "", national_id: "", credit_limit: 0, legal_type: "real", address: "", groups: "", commission_rate: 0 };
      this.showPersonModal = true;
    },
    editPerson(p) {
      this.editingPerson = p;
      this.personForm = { name: p.name, type: p.type, mobile: p.mobile, national_id: p.national_id, credit_limit: p.credit_limit || 0, legal_type: p.legal_type||"real", address: p.address||"", groups: p.groups||"", phone2: p.phone2||"", commission_rate: p.commission_rate||0 };
      this.showPersonModal = true;
    },
    async savePerson() {
      try {
        if (this.editingPerson) await this.req("/api/persons/" + this.editingPerson.id, { method: "PUT", body: JSON.stringify(this.personForm) });
        else await this.req("/api/persons", { method: "POST", body: JSON.stringify(this.personForm) });
        this.showPersonModal = false;
        await this.refreshAll();
      } catch (e) { alert(e.message); }
    },
    async deletePerson(id) {
      if (!confirm("آیا مطمئن هستید؟")) return;
      try { await this.req("/api/persons/" + id, { method: "DELETE" }); await this.refreshAll(); } catch (e) { alert(e.message); }
    },
    openProductModal() {
      this.editingProduct = null;
      this.productForm = { name: "", code: "", unit: "عدد", sale_price: 0, buy_price: 0, stock: 0, reorder_point: 5, max_stock: 0, barcode: "", group_name: "", track_serial: false, track_lot: false, kind: "goods", brand_id: "", tax_code: "" };
      this.showProductModal = true;
    },
    editProduct(p) {
      this.editingProduct = p;
      this.productForm = { name: p.name, code: p.code, unit: p.unit, sale_price: p.sale_price, buy_price: p.buy_price, stock: p.stock, reorder_point: p.reorder_point, max_stock: p.max_stock||0, barcode: p.barcode||"", group_name: p.group_name||"", track_serial: !!p.track_serial, track_lot: !!p.track_lot, kind: p.kind||"goods", brand_id: p.brand_id||"", tax_code: p.tax_code||"" };
      this.showProductModal = true;
    },
    async saveProduct() {
      try {
        if (this.editingProduct) await this.req("/api/products/" + this.editingProduct.id, { method: "PUT", body: JSON.stringify(this.productForm) });
        else await this.req("/api/products", { method: "POST", body: JSON.stringify(this.productForm) });
        this.showProductModal = false;
        await this.refreshAll();
      } catch (e) { alert(e.message); }
    },
    async deleteProduct(id) {
      if (!confirm("آیا مطمئن هستید؟")) return;
      try { await this.req("/api/products/" + id, { method: "DELETE" }); await this.refreshAll(); } catch (e) { alert(e.message); }
    },
    openInvoiceModal(type) {
      this.invoiceType = type;
      this.editingInvoiceId = null;
      this.invoiceForm = { person_id: "", date: JDATE, items: [{ product_id: "", qty: 1, price: 0, unit: "primary" }], discount: 0, discount_percent: 0, freight: 0, customs: 0, other_cost: 0, subtotal: 0, tax: 0, total: 0, due_date: "", marketer_id: "", department_id: "", note: "", no_vat: false };
      this.showInvoiceModal = true;
    },
    async editInvoice(inv) {
      try {
        const d = await this.req("/api/invoices/" + inv.id + "/detail");
        this.invoiceType = d.kind;
        this.editingInvoiceId = d.id;
        this.invoiceForm = {
          person_id: d.person_id, date: d.date,
          items: (d.items && d.items.length) ? d.items.map(i => ({ product_id: i.product_id, qty: i.qty, price: i.price, unit: i.unit || "primary" })) : [{ product_id: "", qty: 1, price: 0, unit: "primary" }],
          discount: d.discount || 0, discount_percent: d.discount_percent || 0,
          freight: d.freight || 0, customs: d.customs || 0, other_cost: d.other_cost || 0,
          subtotal: d.subtotal || 0, tax: d.tax || 0, total: d.total || 0,
          due_date: d.due_date || "", marketer_id: d.marketer_id || "", department_id: d.department_id || "", note: d.note || "", no_vat: !!d.no_vat
        };
        this.showInvoiceModal = true;
      } catch (e) { alert(e.message); }
    },
    async deleteInvoice(inv) {
      if (!confirm("حذف فاکتور " + inv.number + "؟ موجودی و مانده طرف‌حساب برمی‌گردد.")) return;
      try { await this.req("/api/invoices/" + inv.id, { method: "DELETE" }); await this.refreshAll(); }
      catch (e) { alert(e.message); }
    },
    addInvoiceItem() { this.invoiceForm.items.push({ product_id: "", qty: 1, price: 0, unit: "primary" }); },
    updateItemPrice(item) {
      const pr = this.products.find(p => p.id == item.product_id);
      if (pr) item.price = this.invoiceType === "sale" ? pr.sale_price : pr.buy_price;
      this.calcInvoice();
    },
    calcInvoice() {
      let sub = 0;
      this.invoiceForm.items.forEach(it => { sub += (it.qty || 0) * (it.price || 0); });
      this.invoiceForm.subtotal = sub;
      const after = sub - (this.invoiceForm.discount || 0) - Math.round(sub * ((this.invoiceForm.discount_percent || 0)/100));
      this.invoiceForm.tax = this.invoiceForm.no_vat || /proforma|order/.test(this.invoiceType) ? 0 : Math.round(after * ((this.company.vat_rate || 0) / 100));
      this.invoiceForm.total = after + this.invoiceForm.tax;
    },
    async saveInvoice() {
      try {
        this.calcInvoice();
        const payload = {
            kind: this.invoiceType,
            person_id: Number(this.invoiceForm.person_id),
            date: this.invoiceForm.date,
            discount: this.invoiceForm.discount || 0, discount_percent: this.invoiceForm.discount_percent || 0, freight: this.invoiceForm.freight||0, customs: this.invoiceForm.customs||0, other_cost: this.invoiceForm.other_cost||0,
            due_date: this.invoiceForm.due_date, note: this.invoiceForm.note, no_vat: !!this.invoiceForm.no_vat, marketer_id: Number(this.invoiceForm.marketer_id) || null, department_id: Number(this.invoiceForm.department_id) || null,
            items: this.invoiceForm.items.filter(i => i.product_id).map(i => ({ product_id: Number(i.product_id), qty: i.qty, price: i.price, unit: i.unit || "primary" }))
          };
        if (this.editingInvoiceId) await this.req("/api/invoices/" + this.editingInvoiceId, { method: "PUT", body: JSON.stringify(payload) });
        else await this.req("/api/invoices", { method: "POST", body: JSON.stringify(payload) });
        this.editingInvoiceId = null;
        this.showInvoiceModal = false;
        await this.refreshAll();
        alert("فاکتور و سند در دیتابیس ثبت شد");
      } catch (e) { alert(e.message); }
    },
    openJournalModal() {
      this.journalForm = { description: "", date: JDATE, lines: [{ account_id: "", debit: 0, credit: 0 }, { account_id: "", debit: 0, credit: 0 }] };
      this.showJournalModal = true;
    },
    addJournalLine() { this.journalForm.lines.push({ account_id: "", debit: 0, credit: 0 }); },
    async saveJournal() {
      try {
        const lines = (this.journalForm.lines || []).filter(l => l.account_id).map(l => ({ account_id: Number(l.account_id), debit: Number(l.debit||0), credit: Number(l.credit||0), description: this.journalForm.description }));
        await this.req("/api/journals", { method: "POST", body: JSON.stringify({ description: this.journalForm.description, date: this.journalForm.date, lines }) });
        this.showJournalModal = false;
        await this.refreshAll();
      } catch (e) { alert(e.message); }
    },
    async finalizeInvoice(inv) {
      if (!confirm("تبدیل به فاکتور نهایی؟ موجودی و حساب به‌روز می‌شود.")) return;
      try { await this.req("/api/invoices/" + inv.id + "/finalize", { method: "POST" }); await this.refreshAll(); alert("نهایی شد"); }
      catch(e){ alert(e.message); }
    },
    async uploadAttach(object_type, object_id, ev) {
      const file = ev.target.files && ev.target.files[0];
      if (!file) return;
      const fd = new FormData();
      fd.append("file", file);
      const res = await fetch(API("/api/attachments?object_type=" + object_type + "&object_id=" + object_id), {
        method: "POST",
        headers: { Authorization: "Bearer " + this.token, "X-Auth-Token": this.token },
        body: fd
      });
      if (!res.ok) { alert("خطا در آپلود"); return; }
      alert("پیوست ذخیره شد");
      this.attachments = await this.req("/api/attachments?object_type=" + object_type + "&object_id=" + object_id);
    },
    async loadAttachments(object_type, object_id) {
      this.attachments = await this.req("/api/attachments?object_type=" + object_type + "&object_id=" + object_id);
      this.attachTarget = { object_type, object_id };
    },
    async loadCogs() {
      const r = await this.req("/api/reports/cogs");
      this.reportTitle = "بهای تمام‌شده و ارزش موجودی";
      let html = '<table class="w-full text-sm"><tr class="border-b"><th class="text-right">کالا</th><th class="text-right">موجودی</th><th class="text-right">میانگین</th><th class="text-right">ارزش</th></tr>';
      (r.rows||[]).forEach(x => { html += `<tr class="border-b"><td class="py-1">${x.name}</td><td>${x.stock}</td><td>${this.formatNumber(x.avg_cost)}</td><td>${this.formatNumber(x.stock_value)}</td></tr>`; });
      html += `<tr><td colspan="3" class="font-bold py-2">جمع</td><td class="font-bold">${this.formatNumber(r.total_value)}</td></tr></table>`;
      this.reportContent = html; this.currentPage = "reports";
    },
    async saveUserPerms(u, text) {
      try {
        const permissions = (text || "").split(/[،,\s]+/).map(s=>s.trim()).filter(Boolean);
        await this.req("/api/users/" + u.id + "/permissions", { method: "PUT", body: JSON.stringify({ permissions }) });
        alert("دسترسی ذخیره شد");
      } catch(e){ alert(e.message); }
    },
    async loadCashflow() {
      const r = await this.req("/api/reports/cashflow");
      this.reportTitle = "صورت جریان نقدی";
      this.reportContent = `<div class="space-y-2 text-sm"><div>دریافت‌ها: ${this.formatNumber(r.operating_in)}</div><div>پرداخت‌ها: ${this.formatNumber(r.operating_out)}</div><div class="font-bold">خالص عملیاتی: ${this.formatNumber(r.net_operating)}</div><div>مانده نقد: ${this.formatNumber(r.cash_balance)}</div></div>`;
      this.currentPage = "reports";
    },
    printInvoice(inv) {
      window.open(this.link("/api/invoices/" + inv.id + "/print"));
    },
    async voidJournal(j) {
      if (!confirm("ابطال سند؟")) return;
      try { await this.req("/api/journals/" + j.id + "/void", { method: "POST" }); await this.refreshAll(); }
      catch(e){ alert(e.message); }
    },
    async createClosing() {
      try { const r = await this.req("/api/journals/closing", { method: "POST" }); alert("اختتامیه ثبت شد. سود تقریبی: " + this.formatNumber(r.profit)); await this.refreshAll(); }
      catch(e){ alert(e.message); }
    },
    async showKardex(p) {
      try {
        const r = await this.req("/api/kardex/" + p.id);
        this.reportTitle = "کاردکس " + r.product;
        let html = '<table class="w-full text-sm"><tr class="border-b"><th class="text-right py-1">سند</th><th class="text-right">ورود</th><th class="text-right">خروج</th><th class="text-right">مانده</th></tr>';
        (r.rows||[]).forEach(x => { html += `<tr class="border-b"><td class="py-1">${x.doc}</td><td>${x.qty_in}</td><td>${x.qty_out}</td><td>${x.balance}</td></tr>`; });
        this.reportContent = html + "</table>";
        this.currentPage = "reports";
      } catch(e){ alert(e.message); }
    },
    async createOpening() {
      try { const r = await this.req("/api/journals/opening", { method: "POST" }); alert("سند افتتاحیه شماره " + r.number + " ثبت شد"); await this.refreshAll(); }
      catch (e) { alert(e.message); }
    },
    async loadTrial() {
      try { const r = await this.req("/api/trial-balance"); this.trialRows = r.rows || []; }
      catch (e) { alert(e.message); }
    },
    async toggleLock() {
      try {
        await this.req(this.fiscal.locked ? "/api/fiscal/unlock" : "/api/fiscal/lock", { method: "POST" });
        await this.refreshAll();
      } catch (e) { alert(e.message); }
    },
    openCoaModal() { this.coaForm = { code: "", name: "", level: "moein", nature: "debit", parent_code: "" }; this.showCoaModal = true; },
    async saveCoa() {
      try { await this.req("/api/coa", { method: "POST", body: JSON.stringify(this.coaForm) }); this.showCoaModal = false; await this.refreshAll(); }
      catch (e) { alert(e.message); }
    },
    generateReport(type) {
      if (type === "sales") {
        this.reportTitle = "گزارش فروش";
        let html = '<table class="w-full text-sm"><thead><tr class="border-b"><th class="text-right py-2">شماره</th><th class="text-right py-2">مشتری</th><th class="text-right py-2">مبلغ</th></tr></thead><tbody>';
        this.salesInvoices.forEach(i => { html += `<tr class="border-b"><td class="py-2">${i.number}</td><td class="py-2">${i.person_name || this.getPersonName(i.person_id)}</td><td class="py-2">${this.formatNumber(i.total)}</td></tr>`; });
        html += `</tbody></table><div class="mt-4 font-bold">جمع: ${this.formatNumber(this.stats.sales)} ریال</div>`;
        this.reportContent = html;
      } else if (type === "inventory") {
        this.reportTitle = "موجودی کالا";
        let html = '<table class="w-full text-sm"><thead><tr class="border-b"><th class="text-right py-2">کد</th><th class="text-right py-2">نام</th><th class="text-right py-2">موجودی</th></tr></thead><tbody>';
        this.products.forEach(p => { html += `<tr class="border-b"><td class="py-2">${p.code}</td><td class="py-2">${p.name}</td><td class="py-2">${p.stock}</td></tr>`; });
        this.reportContent = html + "</tbody></table>";
      } else if (type === "debtors") {
        this.reportTitle = "بدهکاران";
        let html = '<table class="w-full text-sm"><thead><tr class="border-b"><th class="text-right py-2">نام</th><th class="text-right py-2">مانده</th></tr></thead><tbody>';
        this.customers.filter(c => c.balance > 0).forEach(c => { html += `<tr class="border-b"><td class="py-2">${c.name}</td><td class="py-2">${this.formatNumber(c.balance)}</td></tr>`; });
        this.reportContent = html + "</tbody></table>";
      } else if (type === "pl") {
        this.reportTitle = "سود و زیان ساده";
        const profit = this.stats.sales - this.stats.purchases;
        this.reportContent = `<div class="space-y-2"><div class="flex justify-between"><span>فروش:</span><span>${this.formatNumber(this.stats.sales)}</span></div><div class="flex justify-between"><span>خرید:</span><span>${this.formatNumber(this.stats.purchases)}</span></div><div class="flex justify-between font-bold border-t pt-2"><span>سود تقریبی:</span><span>${this.formatNumber(profit)}</span></div></div>`;
      } else {
        if (type === "daybook") {
        this.reportTitle = "دفتر روزنامه";
        this.journals.forEach(()=>{});
        let html = '<table class="w-full text-sm"><tr class="border-b"><th class="text-right py-1">شماره</th><th class="text-right">شرح</th><th class="text-right">بدهکار</th><th class="text-right">بستانکار</th><th class="text-right">وضعیت</th></tr>';
        this.journals.forEach(j => { html += `<tr class="border-b"><td class="py-1">${j.number}</td><td>${j.description||""}</td><td>${this.formatNumber(j.debit)}</td><td>${this.formatNumber(j.credit)}</td><td>${j.status||""}</td></tr>`; });
        this.reportContent = html + "</table>";
        return;
      }
      this.reportTitle = "تراز آزمایشی نمونه";
        this.reportContent = `<table class="w-full text-sm"><tr class="border-b"><td class="py-2">مطالبات</td><td>${this.formatNumber(this.stats.receivables)}</td></tr><tr class="border-b"><td class="py-2">موجودی کالا</td><td>${this.formatNumber(this.stats.stock_value)}</td></tr><tr class="border-b"><td class="py-2">بدهی تأمین‌کنندگان</td><td>${this.formatNumber(this.stats.payables)}</td></tr></table>`;
      }
    },
    async exportExcel() {
      window.open(this.link("/api/backup"));
    },
    async saveCompany() {
      try {
        await this.req("/api/company", { method: "PUT", body: JSON.stringify({
          name: this.company.name, national_id: this.company.national_id, economic_code: this.company.economic_code, vat_rate: this.company.vat_rate,
          tax_memory: this.company.tax_memory, address: this.company.address, phone: this.company.phone, postal_code: this.company.postal_code,
          invoice_footer: this.company.invoice_footer, allow_negative_stock: !!this.company.allow_negative_stock,
          invoice_prefix_sale: this.company.invoice_prefix_sale, invoice_prefix_buy: this.company.invoice_prefix_buy
        })});
        alert("تنظیمات شرکت ذخیره شد");
      } catch (e) { alert(e.message); }
    },
    async saveWebhookSettings() {
      try {
        await this.req("/api/company", { method: "PUT", body: JSON.stringify({
          webhook_enabled: this.company.webhook_enabled, webhook_url: this.company.webhook_url, webhook_secret: this.company.webhook_secret
        })});
        alert("Webhook ذخیره شد");
      } catch (e) { alert(e.message); }
    },
    async testWebhook() {
      try { await this.req("/api/webhook/test", { method: "POST" }); alert("تست ارسال شد. پنل n8n را ببینید."); }
      catch (e) { alert(e.message); }
    },
    async downloadBackup() {
      const res = await fetch(API("/api/backup"), { headers: { Authorization: "Bearer " + this.token, "X-Auth-Token": this.token, "X-Company": String(this.companyId) } });
      if (!res.ok) { alert("پشتیبان گرفته نشد"); return; }
      const blob = await res.blob();
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = ((res.headers.get("content-disposition") || "").match(/filename="([^"]+)"/) || [0, "backup.sqlite"])[1];
      a.click();
      this.lastBackupAt = new Date().toLocaleString("fa-IR");
    },
    async restoreBackup(ev) {
      const f = ev.target.files[0];
      ev.target.value = "";
      if (!f || !confirm("اطلاعات فعلی «" + this.companyName + "» با فایل «" + f.name + "» جایگزین شود؟")) return;
      const fd = new FormData();
      fd.append("file", f);
      try {
        const res = await fetch(API("/api/restore"), { method: "POST", body: fd, headers: { Authorization: "Bearer " + this.token, "X-Auth-Token": this.token, "X-Company": String(this.companyId) } });
        const j = await res.json();
        if (!res.ok) throw new Error(j.detail || "خطا");
        alert("بازیابی شد. نسخه‌ی قبلی روی سرور با نام " + j.kept + " نگه داشته شد.");
        location.reload();
      } catch (e) { alert(e.message); }
    },
    get companyName() { return (this.companies.find(c => c.id == this.companyId) || {}).name || ""; },
    async switchCompany() {
      localStorage.setItem("acc_company", String(this.companyId));
      await this.refreshAll();
    },
    async createCompany() {
      try {
        const r = await this.req("/api/companies", { method: "POST", body: JSON.stringify({ name: this.companyForm.name, copy_from: this.companyForm.copy ? this.companyId : 0 }) });
        this.companyForm = { name: "", copy: false };
        this.companies = await this.req("/api/companies");
        if (confirm("موسسه ساخته شد. الان به آن بروی؟")) { this.companyId = r.id; await this.switchCompany(); }
      } catch (e) { alert(e.message); }
    },
    async loadUsers() {
      try { this.users = await this.req("/api/users"); this.logs = await this.req("/api/logs"); } catch (e) {}
    },

    async sendTax(row) {
      if (!confirm("صورتحساب برای سازمان امور مالیاتی فرستاده شود؟")) return;
      try { const r = await this.req("/api/tax-invoices/" + row.id + "/send", { method: "POST" }); await this.refreshAll(); alert("ارسال شد؛ شماره پیگیری: " + r.reference + "\nچند دقیقه بعد «استعلام» بزن."); }
      catch(e){ alert(e.message); }
    },
    async inquireTax(row) {
      try { const r = await this.req("/api/tax-invoices/" + row.id + "/inquire", { method: "POST" }); await this.refreshAll(); alert("وضعیت: " + r.status_label); }
      catch(e){ alert(e.message); }
    },
    async cancelTax(row) {
      if (!confirm("صورتحساب " + row.taxid + " در سامانه مؤدیان ابطال شود؟")) return;
      try { await this.req("/api/tax-invoices/" + row.id + "/cancel", { method: "POST" }); await this.refreshAll(); alert("درخواست ابطال فرستاده شد"); }
      catch(e){ alert(e.message); }
    },
    async loadTaxKeys() { try { this.taxKeys = await this.req("/api/tax/keys"); } catch (e) {} },
    async readPemFile(ev, field) {
      const f = ev.target.files[0];
      if (!f) return;
      const buf = new Uint8Array(await f.arrayBuffer());
      let text = new TextDecoder().decode(buf);
      if (!text.includes("-----BEGIN")) text = btoa(String.fromCharCode(...buf));   // DER certificate
      this.taxForm[field] = text;
    },
    async saveTaxKeys() {
      try {
        await this.req("/api/company", { method: "PUT", body: JSON.stringify({ tax_memory: this.company.tax_memory, economic_code: this.company.economic_code, national_id: this.company.national_id }) });
        this.taxKeys = await this.req("/api/tax/keys", { method: "PUT", body: JSON.stringify(this.taxForm) });
        this.taxForm = { private_key: "", certificate: "", env: this.taxKeys.env };
        alert("ذخیره شد");
      } catch (e) { alert(e.message); }
    },
    async taxKeygen() {
      if (this.taxKeys.has_key && !confirm("کلید فعلی جایگزین شود؟ گواهی قبلی دیگر به کار نمی‌آید.")) return;
      try { await this.req("/api/tax/keygen", { method: "POST" }); await this.loadTaxKeys(); } catch (e) { alert(e.message); }
    },
    async taxTest() {
      try { const r = await this.req("/api/tax/test", { method: "POST" }); alert("اتصال برقرار است ✅\n" + JSON.stringify(r.fiscal || {}, null, 1)); }
      catch (e) { alert(e.message); }
    },
    downloadTaxJson(row) {
      const blob = new Blob([row.payload || "{}"], {type:"application/json"});
      const a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = (row.taxid||"tax")+".json"; a.click();
    },
    openSerialModal() {
      this.serialForm = { product_id: "", warehouse_id: this.warehouses[0]?.id || "", serial: "", lot: "", expiry: "" };
      this.showSerialModal = true;
    },
    async saveSerial() {
      try {
        await this.req("/api/serials", { method: "POST", body: JSON.stringify({ ...this.serialForm, product_id: Number(this.serialForm.product_id), warehouse_id: Number(this.serialForm.warehouse_id) }) });
        this.showSerialModal = false; await this.refreshAll();
      } catch(e){ alert(e.message); }
    },
    async serialOut(s) {
      try { await this.req("/api/serials/" + s.id + "/out", { method: "POST" }); await this.refreshAll(); }
      catch(e){ alert(e.message); }
    },
    openCountModal() {
      this.countForm = { warehouse_id: this.warehouses[0]?.id || "", date: JDATE, items: this.products.map(p => ({ product_id: p.id, counted_qty: p.stock || 0 })) };
      this.showCountModal = true;
    },
    async saveCount() {
      try {
        const items = this.countForm.items.filter(i => i.product_id).map(i => ({ product_id: Number(i.product_id), counted_qty: Number(i.counted_qty) }));
        await this.req("/api/stock-counts", { method: "POST", body: JSON.stringify({ warehouse_id: Number(this.countForm.warehouse_id), date: this.countForm.date, items }) });
        this.showCountModal = false; await this.refreshAll();
      } catch(e){ alert(e.message); }
    },
    async saveBranch() {
      try { await this.req("/api/branches", { method:"POST", body: JSON.stringify(this.branchForm) }); this.branchForm={name:"",city:""}; await this.refreshAll(); }
      catch(e){ alert(e.message); }
    },
    async saveCurrency() {
      try { await this.req("/api/currencies", { method:"POST", body: JSON.stringify(this.currencyForm) }); await this.refreshAll(); }
      catch(e){ alert(e.message); }
    },
    openStmtModal(){ this.showStmtModal = true; },
    async saveStmt() {
      try { await this.req("/api/bank-statements", { method:"POST", body: JSON.stringify({...this.stmtForm, account_id:Number(this.stmtForm.account_id)}) }); await this.refreshAll(); }
      catch(e){ alert(e.message); }
    },
    async matchStmt(s) {
      try { await this.req("/api/bank-statements/"+s.id+"/match", { method:"POST" }); await this.refreshAll(); }
      catch(e){ alert(e.message); }
    },
    async loadBalanceSheet() {
      const r = await this.req("/api/reports/balance-sheet");
      this.reportTitle = "ترازنامه";
      this.reportContent = `<div class="space-y-2 text-sm"><div>موجودی نقد: ${this.formatNumber(r.assets.cash)}</div><div>مطالبات: ${this.formatNumber(r.assets.receivables)}</div><div>موجودی کالا: ${this.formatNumber(r.assets.inventory)}</div><div class="font-bold">جمع دارایی: ${this.formatNumber(r.assets.total)}</div><div>بدهی تأمین‌کنندگان: ${this.formatNumber(r.liabilities.payables)}</div><div>حقوق صاحبان سهام: ${this.formatNumber(r.equity.capital_and_profit)}</div></div>`;
      this.currentPage = "reports";
    },
    async loadDues() {
      const r = await this.req("/api/reports/dues");
      this.reportTitle = "سررسید مطالبات و بدهی‌ها";
      this.reportContent = `<div class="text-sm"><div class="font-semibold mb-1">چک‌های دریافتنی</div>` + (r.receivable_cheques||[]).map(c=>`<div>${c.number} - ${this.formatNumber(c.amount)} - ${c.due_date}</div>`).join("") + `<div class="font-semibold mt-3 mb-1">چک‌های پرداختنی</div>` + (r.payable_cheques||[]).map(c=>`<div>${c.number} - ${this.formatNumber(c.amount)} - ${c.due_date}</div>`).join("") + `</div>`;
      this.currentPage = "reports";
    },
    async loadStock() {
      const q = this.stockWarehouse ? ("?warehouse_id=" + this.stockWarehouse) : "";
      this.stockRows = await this.req("/api/stock" + q);
    },
    openWhModal() { this.whForm = { name: "", is_default: false }; this.showWhModal = true; },
    async saveWarehouse() {
      try { await this.req("/api/warehouses", { method: "POST", body: JSON.stringify(this.whForm) }); this.showWhModal=false; await this.refreshAll(); }
      catch(e){ alert(e.message); }
    },
    openWhDoc(kind) {
      this.whDocForm = { kind, warehouse_id: this.warehouses[0]?.id || "", to_warehouse_id: this.warehouses[1]?.id || "", date: JDATE, items: [{product_id:"", qty:1}] };
      this.showWhDocModal = true;
    },
    async saveWhDoc() {
      try {
        const body = {
          ...this.whDocForm,
          warehouse_id: Number(this.whDocForm.warehouse_id),
          to_warehouse_id: this.whDocForm.to_warehouse_id ? Number(this.whDocForm.to_warehouse_id) : null,
          items: this.whDocForm.items.filter(i=>i.product_id).map(i=>({product_id:Number(i.product_id), qty:Number(i.qty)}))
        };
        await this.req("/api/warehouse-docs", { method: "POST", body: JSON.stringify(body) });
        this.showWhDocModal = false;
        await this.refreshAll();
      } catch(e){ alert(e.message); }
    },
    openTxn(kind) {
      this.txnForm = { kind, account_id: this.accounts[0]?.id || "", to_account_id: "", person_id: "", invoice_id: "", amount: 0, date: JDATE, description: "", counter_account_id: "" };
      this.showTxnModal = true;
    },
    async saveTxn() {
      try {
        const body = { ...this.txnForm, account_id: Number(this.txnForm.account_id), amount: Number(this.txnForm.amount) };
        if (body.to_account_id) body.to_account_id = Number(body.to_account_id);
        if (body.person_id) body.person_id = Number(body.person_id); else delete body.person_id;
        if (body.invoice_id) body.invoice_id = Number(body.invoice_id); else delete body.invoice_id;
        if (body.counter_account_id) body.counter_account_id = Number(body.counter_account_id); else delete body.counter_account_id;
        await this.req("/api/treasury", { method: "POST", body: JSON.stringify(body) });
        this.showTxnModal = false;
        await this.refreshAll();
      } catch (e) { alert(e.message); }
    },
    openCheque(direction) {
      this.chequeForm = { number: "", direction, person_id: "", amount: 0, due_date: JDATE, bank_name: "" };
      this.showChequeModal = true;
    },
    async saveCheque() {
      try {
        await this.req("/api/cheques", { method: "POST", body: JSON.stringify({ ...this.chequeForm, person_id: Number(this.chequeForm.person_id), amount: Number(this.chequeForm.amount) }) });
        this.showChequeModal = false;
        await this.refreshAll();
      } catch (e) { alert(e.message); }
    },
    /** Picks an item from a short numbered list with prompt(). */
    pick(title, items, label) {
      if (!items.length) { alert("موردی برای انتخاب نیست"); return null; }
      const txt = items.map((x, i) => (i + 1) + ") " + label(x)).join("\n");
      const n = Number(prompt(title + "\n" + txt, "1"));
      return items[n - 1] || null;
    },
    async chequeAct(c, action) {
      try {
        const body = { action };
        if (["deposit", "collect", "pay"].includes(action)) {
          const a = c.account_id && action !== "deposit" ? { id: c.account_id } : this.pick("به کدام حساب؟", this.accounts, x => x.name);
          if (!a) return;
          body.account_id = a.id;
        }
        if (action === "spend") {
          const p = this.pick("چک را به چه کسی دادی؟", this.persons.filter(x => x.id !== c.person_id), x => x.name + " (" + x.code + ")");
          if (!p) return;
          body.person_id = p.id;
        }
        await this.req("/api/cheques/" + c.id + "/action", { method: "POST", body: JSON.stringify(body) });
        await this.refreshAll();
      } catch (e) { alert(e.message); }
    },
    openAccountModal() {
      this.accountForm = { name: "", kind: "cash", account_no: "", balance: 0 };
      this.showAccountModal = true;
    },
    async saveAccount() {
      try {
        await this.req("/api/accounts", { method: "POST", body: JSON.stringify(this.accountForm) });
        this.showAccountModal = false;
        await this.refreshAll();
      } catch (e) { alert(e.message); }
    },
    async saveUser() {
      try {
        await this.req("/api/users", { method: "POST", body: JSON.stringify(this.userForm) });
        this.showUserModal = false;
        await this.loadUsers();
      } catch (e) { alert(e.message); }
    }
  };
}
