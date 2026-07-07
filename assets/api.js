const API_BASE = '/api/v1';

const API_KEY_STORAGE = 'image_api_key';

function readStoredKey() {
    try {
        return localStorage.getItem(API_KEY_STORAGE) || '';
    } catch {
        return '';
    }
}

function storeKey(key) {
    try {
        if (key) localStorage.setItem(API_KEY_STORAGE, key);
        else localStorage.removeItem(API_KEY_STORAGE);
    } catch {}
}

async function request(url, options = {}) {
    options.credentials = 'same-origin';
    if (!options.headers) options.headers = {};

    const storedKey = readStoredKey();
    if (storedKey && !options.headers['X-API-Key']) {
        options.headers['X-API-Key'] = storedKey;
    }

    const res = await fetch(url, options);
    let data;
    try {
        data = await res.json();
    } catch (e) {
        data = { code: res.status, message: res.statusText, data: null };
    }
    return data;
}

const api = {
    async authStatus() {
        return request(`${API_BASE}/auth.php`);
    },

    async login(user, password, remember = true) {
        const body = new URLSearchParams({ user, password, remember: remember ? '1' : '0' });
        const res = await request(`${API_BASE}/auth.php`, { method: 'POST', body });
        return res;
    },

    async logout() {
        storeKey('');
        const body = new URLSearchParams({ action: 'logout' });
        return request(`${API_BASE}/auth.php`, { method: 'POST', body });
    },

    async me() {
        return request(`${API_BASE}/me.php`);
    },

    async upload(file, options = {}) {
        const formData = new FormData();
        formData.append('file', file);
        if (options.convert) formData.append('convert', options.convert);
        const storedKey = readStoredKey();
        if (storedKey) formData.append('token', storedKey);
        return request(`${API_BASE}/upload.php`, { method: 'POST', body: formData });
    },

    async listImages(page = 1, limit = 20) {
        const params = new URLSearchParams({ page, limit });
        return request(`${API_BASE}/images.php?${params.toString()}`);
    },

    async deleteImage(id) {
        return request(`${API_BASE}/images.php?id=${id}`, { method: 'DELETE' });
    },
};

async function copyText(text) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const input = document.createElement('textarea');
            input.value = text;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
        }
        return true;
    } catch (e) {
        return false;
    }
}

function formatSize(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function formatDate(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toLocaleString('zh-CN', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}
