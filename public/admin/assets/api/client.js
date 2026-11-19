import { state } from '../app/store.js?v=0.8.7';
const errors = { reauthentication_required: 'Подтвердите личность в разделе Настройки → Подтверждение личности, затем повторите операцию.', totp_enrollment_required: 'Необходимо подключить второй фактор в настройках.', second_factor_required: 'Требуется второй фактор.', invalid_otp: 'Неверный или уже использованный код. Проверьте время устройства.', unauthorized: 'Неверные учётные данные или сессия истекла.', forbidden: 'Недостаточно прав для операции.', csrf_failed: 'Сессия изменилась. Обновите страницу и повторите операцию.', service_unavailable: 'Сервис или база данных временно недоступны.', rate_limited: 'Слишком много запросов. Повторите позже.', last_administrator: 'Нельзя отключить последнего администратора.', conflict: 'Конфликт с существующими данными.', invalid_password: 'Текущий пароль неверен.', not_found: 'Объект не найден.', page_changed: 'Страница изменилась. Обновите поиск перед экспортом.' };
const statuses = { 401: errors.unauthorized, 403: errors.forbidden, 409: errors.conflict, 422: 'Проверьте введённые данные.', 429: errors.rate_limited, 503: errors.service_unavailable };
export class ApiError extends Error {
    constructor(status, data = {}, headers = new Headers()) {
        const id = data?.request_id || headers.get('X-Request-ID');
        const retry = headers.get('Retry-After');
        const wait = status === 429 && /^[0-9]{1,6}$/.test(retry || '') ? ` Повторите не ранее чем через ${retry} с.` : '';
        super((errors[data?.error] || data?.message || statuses[status] || `HTTP ${status}`) + wait + (id ? ` (ID: ${id})` : ''));
        this.name = 'ApiError'; this.status = status; this.data = data; this.requestId = id; this.retryAfter = headers.get('Retry-After');
    }
}
const aborted = () => new DOMException('Session changed', 'AbortError');
export function createApiClient({ origin, prefix, fetcher = fetch, session, onUnauthorized = () => {} }) {
    if (!prefix.startsWith('/') || prefix.startsWith('//') || /[?#\\]/.test(prefix)) throw new Error('Недопустимый адрес API.');
    const base = new URL(prefix.replace(/\/$/, '') + '/', origin);
    if (base.origin !== origin) throw new Error('Недопустимый адрес API.');
    // No automatic retries: a lost response does not prove that a write failed.
    async function send(path, { method = 'GET', body, query, signal, raw = false, wireText = false, withStatus = false, login = false, allowStatus = [], ifMatch = null, bearer = null, extraHeaders = {} } = {}, root = false) {
        if (!path.startsWith('/') || path.startsWith('//') || /[?#\\]/.test(path)) throw new Error('Недопустимый адрес API.');
        const url = root ? new URL(path, origin) : new URL(base.pathname + path.slice(1), origin);
        if (url.origin !== origin || (!root && !url.pathname.startsWith(base.pathname))) throw new Error('Недопустимый адрес API.');
        for (const [k, v] of Object.entries(query || {})) if (v !== '' && v !== null && v !== undefined) url.searchParams.set(k, String(v));
        method = method.toUpperCase();
        const authEpoch = session.authEpoch, timeout = AbortSignal.timeout(60000);
        const requestSignal = AbortSignal.any([timeout, signal, session.sessionController?.signal].filter(Boolean));
        const check = () => { if (authEpoch !== session.authEpoch || signal?.aborted || session.sessionController?.signal.aborted) throw aborted(); if (timeout.aborted) throw new Error('Время ожидания API истекло. Проверьте результат операции перед повтором.'); };
        const headers = { ...extraHeaders, Accept: raw ? '*/*' : 'application/json' };
        if (ifMatch) headers['If-Match'] = ifMatch;
        if (body !== undefined) { headers['Content-Type'] = 'application/json'; if (typeof body !== 'string' || !bearer) body = JSON.stringify(body); }
        if (bearer) headers.Authorization = 'Bearer ' + bearer;
        else if (!['GET', 'HEAD'].includes(method) && !login && session.csrf) headers['X-CSRF-Token'] = session.csrf;
        try {
            check();
            const response = await fetcher(url, { method, headers, body, signal: requestSignal, credentials: bearer ? 'omit' : 'same-origin', cache: 'no-store', redirect: 'error' });
            check();
            if (!response.ok && !allowStatus.includes(response.status)) {
                let data = {}; try { data = await response.json(); } catch { /* status still authoritative */ }
                check();
                const error = new ApiError(response.status, data, response.headers);
                if (response.status === 401 && !login && !bearer) onUnauthorized();
                throw error;
            }
            if (raw) return response;
            if (wireText) { const value = await response.text(); check(); return value; }
            if (response.status === 204) return null;
            let data; try { data = await response.json(); } catch { check(); throw new Error('Сервер вернул некорректный JSON.'); }
            check(); return withStatus ? {status:response.status,data} : data;
        } catch (error) {
            if (error instanceof ApiError) throw error;
            check();
            if (error instanceof TypeError) throw new Error('Не удалось связаться с API. Проверьте соединение.');
            throw error;
        }
    }
    return { request: send, get: (path, query, signal) => send(path, { query, signal }), write: (path, method, body) => send(path, { method, body }), ready: signal => send('/ready', { signal, withStatus: true, allowStatus: [503] }, true) };
}
let client, unauthorized = () => {};
export function installUnauthorizedHandler(fn) { unauthorized = fn; }
export function apiPrefix() { return document.querySelector('meta[name=cajeer-api]').content.replace(/\/$/, ''); }
function instance() { return client ??= createApiClient({ origin: location.origin, prefix: apiPrefix(), session: state, onUnauthorized: () => unauthorized() }); }
export const request = (...args) => instance().request(...args);
export const get = (...args) => instance().get(...args);
export const write = (...args) => instance().write(...args);
export const ready = (...args) => instance().ready(...args);
