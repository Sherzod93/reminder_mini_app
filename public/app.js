'use strict';

const tg = window.Telegram && window.Telegram.WebApp;
const $ = (id) => document.getElementById(id);

const DAYS_SHORT = ['Ду', 'Се', 'Чо', 'Па', 'Жу', 'Ша', 'Як'];
const DAYS_FULL = ['Душанба', 'Сешанба', 'Чоршанба', 'Пайшанба', 'Жума', 'Шанба', 'Якшанба'];
const MONTHS = ['январ', 'феврал', 'март', 'апрел', 'май', 'июн', 'июл', 'август', 'сентябр', 'октябр', 'ноябр', 'декабр'];
const BEFORE = [0, 5, 10, 15, 30, 60];
const TITLES = { today: 'Бугун', tasks: 'Вазифалар', settings: 'Намоз' };
const QUICK = [
  ['📚 Дарс қилиш', '16:00'],
  ['📖 Китоб ўқиш', '21:00'],
  ['🏃 Спорт', '07:00'],
  ['📿 Қуръон ўқиш', '06:30'],
  ['💧 Сув ичиш', '10:00'],
];
const MOON = 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z';

let S = null;
let tab = 'today';
let editing = null;
let pickedDays = new Set();

/* ---------- helpers ---------- */

function el(tag, attrs = {}, ...kids) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'class') n.className = v;
    else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
    else if (v === true) n.setAttribute(k, '');
    else if (v !== false && v != null) n.setAttribute(k, v);
  }
  for (const k of kids.flat()) if (k != null) n.append(k);
  return n;
}

function icon(path, color) {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  const p = document.createElementNS(ns, 'path');
  p.setAttribute('d', path);
  svg.append(p);
  return el('span', { class: `row-icon ${color}` }, svg);
}

function haptic(kind = 'light') {
  try { tg.HapticFeedback.impactOccurred(kind); } catch (_) {}
}
function hapticOk() {
  try { tg.HapticFeedback.notificationOccurred('success'); } catch (_) {}
}

let toastTimer;
function toast(msg) {
  const t = $('toast');
  t.textContent = msg;
  t.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.hidden = true; }, 2000);
}

function beforeLabel(m) { return m ? `${m} дақ. олдин` : 'Вақтида'; }

function daysLabel(days) {
  if (days === '1234567') return 'Ҳар куни';
  if (days === '12345') return 'Иш кунлари';
  if (days === '67') return 'Дам олиш кунлари';
  return days.split('').map((d) => DAYS_SHORT[d - 1]).join(', ');
}

function nowHHMM() {
  return new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Tashkent', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date());
}
const toMin = (hhmm) => { const [h, m] = hhmm.split(':').map(Number); return h * 60 + m; };
function untilText(hhmm) {
  const d = toMin(hhmm) - toMin(nowHHMM());
  if (d <= 0) return 'ҳозир';
  const h = Math.floor(d / 60), m = d % 60;
  return 'яна ' + (h ? `${h} с. ` : '') + (m ? `${m} дақ.` : '');
}

async function api(action, body) {
  const res = await fetch(`api.php?action=${action}`, {
    method: body ? 'POST' : 'GET',
    headers: { 'Content-Type': 'application/json', 'X-Telegram-Init-Data': tg ? tg.initData : '' },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
  S = data;
  render();
  return data;
}

/* ---------- today ---------- */

function todayItems() {
  const items = [];
  if (S.namaz && S.user.namaz_enabled) {
    const times = Object.values(S.namaz.times);
    for (const [i, [key, time]] of Object.entries(S.namaz.times).entries()) {
      if (!S.prayers[key]) continue;
      items.push({
        ref: `n:${key}`, time, namaz: true, until: times[i + 1] || null,
        title: `${S.namaz.labels[key]} намози`,
      });
    }
  }
  for (const t of S.tasks) {
    if (!t.enabled || !t.days.includes(String(S.weekday))) continue;
    items.push({
      ref: `t:${t.id}`, time: t.time, end: t.end_time || null, until: t.end_time || null,
      title: t.title, note: t.note, task: t,
    });
  }
  items.sort((a, b) => a.time.localeCompare(b.time) || (a.namaz ? -1 : 1));
  return items;
}

function renderToday() {
  const items = todayItems();
  const done = new Set(S.done);
  const now = nowHHMM();
  let nextMarked = false;
  const list = $('todayList');
  list.replaceChildren();

  const skipped = new Set(S.skipped || []);
  for (const it of items) {
    const isDone = done.has(it.ref);
    const isSkipped = !isDone && skipped.has(it.ref);
    const started = it.time < now;
    const inProgress = !!it.end && started && it.end > now; // идёт сейчас — не просрочено
    const late = started && !inProgress;
    let cls = 'row task' + (it.namaz ? ' namaz' : '') + (isDone ? ' done' : '') + (late ? ' late' : '') + (isSkipped ? ' skipped' : '');
    if (!isDone && (inProgress || (!late && !nextMarked))) { cls += ' next'; nextMarked = true; }

    const sub = [el('span', { class: 't' }, it.end ? `${it.time}–${it.end}` : it.time)];
    if (inProgress && !isDone) sub.push(' · ҳозир');
    if (isSkipped) sub.push(' · ⏭ Бугун ўтказилди');
    if (it.task && it.task.remind_before) sub.push(` · 🔔 ${beforeLabel(it.task.remind_before)}`);
    if (it.note) sub.push(` · ${it.note}`);

    // Началось и не сделано: «Ўтказиш» — сегодня больше не напоминать. Намаз напоминается до
    // следующего намаза, задача с окончанием — до окончания; после этого кнопка не нужна.
    const stillReminded = !it.until || it.until > now;
    const pill = !isDone && started && stillReminded
      ? el('button', {
        class: 'pill' + (isSkipped ? ' on' : ''), type: 'button',
        onclick: (e) => { e.stopPropagation(); toggleSkip(it.ref, !isSkipped); },
      }, isSkipped ? 'Қайтариш' : 'Ўтказиш')
      : (it.namaz ? el('span', { class: 'badge' }, 'Намоз') : null);

    list.append(el('li', { class: cls + (it.task ? ' tap' : '') },
      el('button', {
        class: 'check' + (isDone ? ' on' : ''), type: 'button', 'aria-label': 'Бажарилди',
        onclick: (e) => { e.stopPropagation(); toggleDone(it.ref, !isDone); },
      }),
      el('div', { class: 'task-body', onclick: () => it.task && openEditor(it.task) },
        el('div', { class: 'task-title' }, it.title),
        el('div', { class: 'task-sub' }, sub),
      ),
      pill,
    ));
  }
  $('todayEmpty').hidden = items.length > 0;

  const total = items.length;
  const doneCount = items.filter((i) => done.has(i.ref)).length;
  $('ringText').textContent = `${doneCount}/${total}`;
  const p = total ? doneCount / total : 0;
  $('ringFg').style.strokeDashoffset = String(97.4 * (1 - p));

  // карточка «следующий намаз»
  const next = S.namaz && S.user.namaz_enabled
    ? Object.entries(S.namaz.times).find(([, t]) => t > now)
    : null;
  $('hero').hidden = !next;
  if (next) {
    $('heroLabel').textContent = `Кейинги намоз · ${untilText(next[1])}`;
    $('heroTitle').textContent = S.namaz.labels[next[0]];
    $('heroTime').textContent = next[1];
  }
}

async function toggleDone(ref, value) {
  value ? hapticOk() : haptic();
  S.done = value ? [...S.done, ref] : S.done.filter((r) => r !== ref);
  render();
  try { await api('done', { ref, done: value }); } catch (e) { toast('Хатолик: ' + e.message); load(); }
}

async function toggleSkip(ref, value) {
  haptic();
  S.skipped = value ? [...(S.skipped || []), ref] : (S.skipped || []).filter((r) => r !== ref);
  render();
  try {
    await api('skip', { ref, skip: value });
    toast(value ? '⏭ Бугун бошқа эслатилмайди' : '🔁 Эслатма қайтарилди');
  } catch (e) { toast('Хатолик: ' + e.message); load(); }
}

/* ---------- tasks ---------- */

function renderTasks() {
  const list = $('taskList');
  list.replaceChildren();
  for (const t of S.tasks) {
    const sw = el('input', {
      type: 'checkbox', class: 'switch', checked: t.enabled,
      onclick: (e) => e.stopPropagation(),
      onchange: (e) => { haptic(); saveTask({ ...t, enabled: e.target.checked }, true); },
    });
    const sub = [el('span', { class: 't' }, t.end_time ? `${t.time}–${t.end_time}` : t.time), ` · ${daysLabel(t.days)}`];
    if (t.remind_before) sub.push(` · 🔔 ${beforeLabel(t.remind_before)}`);
    list.append(el('li', { class: 'row task tap' + (t.enabled ? '' : ' off'), onclick: () => openEditor(t) },
      el('div', { class: 'task-body' },
        el('div', { class: 'task-title' }, t.title),
        el('div', { class: 'task-sub' }, sub),
      ),
      sw,
    ));
  }
  $('tasksEmpty').hidden = S.tasks.length > 0;
  $('tasksHead').hidden = S.tasks.length === 0;
}

function renderQuickAdd() {
  $('quickAdd').replaceChildren(...QUICK.map(([title, time]) =>
    el('button', { class: 'chip', type: 'button', onclick: () => openEditor({ title, time, days: '1234567', remind_before: 0, note: '' }) }, title)));
}

function openEditor(task) {
  editing = task || null;
  const f = $('sheet');
  const t = task || { title: '', time: '09:00', days: '1234567', remind_before: 0, note: '' };
  f.elements.title.value = t.title;
  f.elements.time.value = t.time;
  setEnd(t.end_time || '');
  f.elements.note.value = t.note || '';
  f.elements.remind_before.value = String(t.remind_before);
  pickedDays = new Set(t.days.split(''));
  renderDays();
  $('sheetTitle').textContent = t.id ? 'Таҳрирлаш' : 'Янги вазифа';
  $('delGroup').hidden = !t.id;
  $('sheet').hidden = false;
  $('sheetBg').hidden = false;
  haptic();
  if (tg) tg.BackButton.show();
  if (!t.title) setTimeout(() => f.elements.title.focus(), 300);
}

/** Время окончания: переключатель включает поле; по умолчанию — начало + 1 час. */
function setEnd(value) {
  $('endOn').checked = !!value;
  $('endTime').hidden = !value;
  $('endTime').value = value;
}
$('endOn').addEventListener('change', (e) => {
  haptic();
  if (!e.target.checked) { setEnd(''); return; }
  const [h, m] = ($('sheet').elements.time.value || '09:00').split(':').map(Number);
  setEnd(`${String(Math.min(h + 1, 23)).padStart(2, '0')}:${String(h + 1 > 23 ? 59 : m).padStart(2, '0')}`);
});

function closeEditor() {
  $('sheet').hidden = true;
  $('sheetBg').hidden = true;
  editing = null;
  if (tg) tg.BackButton.hide();
}

function renderDays() {
  $('daysPick').replaceChildren(...DAYS_SHORT.map((d, i) => {
    const v = String(i + 1);
    return el('button', {
      type: 'button', class: pickedDays.has(v) ? 'on' : '',
      onclick: () => { pickedDays.has(v) ? pickedDays.delete(v) : pickedDays.add(v); haptic(); renderDays(); },
    }, d);
  }));
}

async function saveTask(t, quiet) {
  try {
    await api('task_save', t);
    if (!quiet) { hapticOk(); toast('✓ Сақланди'); }
  } catch (e) {
    toast(e.message === 'title' ? 'Номини киритинг' : e.message === 'days' ? 'Камида битта кун танланг' : e.message === 'end_time' ? 'Тугаш вақти бошланишдан кейин бўлсин' : 'Хатолик: ' + e.message);
    throw e;
  }
}

$('sheet').addEventListener('submit', async (e) => {
  e.preventDefault();
  const f = e.target;
  const body = {
    id: editing && editing.id ? editing.id : undefined,
    title: f.elements.title.value.trim(),
    time: f.elements.time.value,
    end_time: $('endOn').checked ? $('endTime').value : '',
    days: [...pickedDays].sort().join(''),
    remind_before: Number(f.elements.remind_before.value),
    note: f.elements.note.value.trim(),
    enabled: editing && editing.id ? editing.enabled : true,
  };
  if (!body.title) { toast('Номини киритинг'); return; }
  if (!body.days) { toast('Камида битта кун танланг'); return; }
  if ($('endOn').checked && (!body.end_time || body.end_time <= body.time)) {
    toast('Тугаш вақти бошланишдан кейин бўлсин'); return;
  }
  try { await saveTask(body); closeEditor(); } catch (_) {}
});

$('delBtn').addEventListener('click', () => {
  const t = editing;
  const go = async () => { await api('task_delete', { id: t.id }); closeEditor(); toast('Ўчирилди'); };
  if (tg && tg.isVersionAtLeast && tg.isVersionAtLeast('6.2')) {
    tg.showPopup({
      title: 'Вазифани ўчириш',
      message: `«${t.title}» ўчирилсинми?`,
      buttons: [{ id: 'del', type: 'destructive', text: 'Ўчириш' }, { type: 'cancel' }],
    }, (id) => id === 'del' && go());
  } else if (confirm(`«${t.title}» ўчирилсинми?`)) go();
});
$('cancelBtn').addEventListener('click', closeEditor);
$('sheetBg').addEventListener('click', closeEditor);
$('addBtn').addEventListener('click', () => openEditor(null));

/* ---------- settings ---------- */

function renderSettings() {
  $('namazEnabled').checked = S.user.namaz_enabled;
  $('namazBefore').value = String(S.user.namaz_before);
  const box = $('prayerList');
  box.replaceChildren();
  if (!S.namaz) {
    $('namazHint').textContent = 'Намоз вақтларини юклаб бўлмади. Кейинроқ уриниб кўринг.';
    return;
  }
  for (const [key, time] of Object.entries(S.namaz.times)) {
    box.append(el('li', { class: 'row' },
      icon(MOON, 'indigo'),
      el('span', { class: 'row-title' }, S.namaz.labels[key]),
      el('span', { class: 'row-value' }, time),
      el('input', {
        type: 'checkbox', class: 'switch', checked: !!S.prayers[key], disabled: !S.user.namaz_enabled,
        onchange: (e) => { haptic(); api('settings', { prayers: { [key]: e.target.checked } }).catch((er) => toast(er.message)); },
      }),
    ));
  }
  $('namazHint').textContent = 'Манба: namoz-vaqti.uz, Тошкент шаҳри.' + (S.namaz.stale ? ' Бугунги вақтлар ҳали янгиланмаган.' : '');
}

$('namazEnabled').addEventListener('change', (e) => { haptic(); api('settings', { namaz_enabled: e.target.checked }).catch((er) => toast(er.message)); });
$('namazBefore').addEventListener('change', (e) => api('settings', { namaz_before: Number(e.target.value) }).catch((er) => toast(er.message)));

/* ---------- shell ---------- */

function render() {
  if (!S) return;
  const [, m, d] = S.today.split('-').map(Number);
  $('topDate').textContent = `${DAYS_FULL[S.weekday - 1]}, ${d} ${MONTHS[m - 1]}`;

  const b = $('banner');
  b.hidden = S.user.can_message;
  b.textContent = 'Эслатмалар келиши учун ботга /start ёзинг';

  renderToday();
  renderTasks();
  renderSettings();
}

function switchTab(name) {
  if (name === tab) return;
  tab = name;
  document.querySelectorAll('.tab').forEach((s) => { s.hidden = s.dataset.tab !== name; });
  document.querySelectorAll('.tabbar button').forEach((b) => b.classList.toggle('active', b.dataset.go === name));
  $('pageTitle').textContent = TITLES[name];
  try { tg.HapticFeedback.selectionChanged(); } catch (_) {}
  window.scrollTo(0, 0);
}
document.querySelectorAll('.tabbar button').forEach((b) => b.addEventListener('click', () => switchTab(b.dataset.go)));

function fillSelects() {
  const opts = () => BEFORE.map((m) => el('option', { value: String(m) }, beforeLabel(m)));
  $('sheet').elements.remind_before.replaceChildren(...opts());
  $('namazBefore').replaceChildren(...opts());
}

function applyTheme() {
  const dark = tg ? tg.colorScheme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  if (tg && tg.isVersionAtLeast && tg.isVersionAtLeast('6.1')) {
    const bg = dark ? '#000000' : '#f2f2f7';
    try { tg.setHeaderColor(bg); tg.setBackgroundColor(bg); } catch (_) {}
  }
  if (tg && tg.isVersionAtLeast && tg.isVersionAtLeast('7.10')) {
    try { tg.setBottomBarColor(dark ? '#161617' : '#f9f9f9'); } catch (_) {}
  }
}

async function load() {
  try { await api('state'); } catch (e) {
    if (e.message === 'unauthorized') $('blocker').hidden = false;
    else toast('Хатолик: ' + e.message);
  }
}

(function init() {
  fillSelects();
  renderQuickAdd();
  applyTheme();
  if (!tg || !tg.initData) { $('blocker').hidden = false; return; }
  tg.ready();
  tg.expand();
  try { tg.disableVerticalSwipes(); } catch (_) {}
  tg.onEvent('themeChanged', applyTheme);
  tg.BackButton.onClick(closeEditor);
  load();
  setInterval(() => { if (document.visibilityState === 'visible' && $('sheet').hidden) load(); }, 60000);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') load(); });
})();
