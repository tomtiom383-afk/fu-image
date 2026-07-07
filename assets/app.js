const { createApp, ref, computed, onMounted, watch } = Vue;

createApp({
    setup() {
        // State
        const view = ref('upload');
        const dark = ref(false);
        const isAdmin = ref(false);
        const user = ref({});
        const stats = ref({ total_images: 0, daily_uploads: 0, total_size: 0 });
        const showLogin = ref(false);
        const showUserMenu = ref(false);
        const showSettings = ref(false);
        const dragover = ref(false);
        const loading = ref(false);
        const toasts = ref([]);
        const fileInput = ref(null);

        const loginForm = ref({ user: '', password: '' });
        const loginLoading = ref(false);

        const settings = ref({
            convertWebp: false,
            autoCopy: true,
            defaultFormat: 'markdown',
        });

        const uploading = ref([]);
        const results = ref([]);
        const resultFormat = ref('markdown');
        const history = ref([]);
        const pagination = ref({ page: 1, limit: 20, total: 0, pages: 0 });
        const selectedImages = ref([]);
        const previewImage = ref(null);
        const uploadProgress = computed(() => {
            if (uploading.value.length === 0) return 0;
            const total = uploading.value.reduce((sum, item) => sum + Number(item.progress || 0), 0);
            return Math.min(100, Math.round(total / uploading.value.length));
        });

        const formats = [
            { key: 'url', label: 'URL' },
            { key: 'markdown', label: 'Markdown' },
            { key: 'html', label: 'HTML' },
            { key: 'bbcode', label: 'BBCode' },
        ];

        // Theme
        function initTheme() {
            const saved = localStorage.getItem('theme');
            if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                dark.value = true;
                document.documentElement.classList.add('dark');
            }
        }

        function toggleTheme() {
            dark.value = !dark.value;
            if (dark.value) {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            } else {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            }
        }

        // Toast
        function toast(message, type = 'success') {
            const id = Date.now() + Math.random();
            toasts.value.push({ id, message, type });
            setTimeout(() => {
                toasts.value = toasts.value.filter(t => t.id !== id);
            }, 3000);
        }

        // Auth
        async function checkAuth() {
            const res = await api.authStatus();
            if (res.code === 200 && res.data.logged_in) {
                isAdmin.value = true;
                await loadMe();
            }
        }

        async function login() {
            loginLoading.value = true;
            const res = await api.login(loginForm.value.user, loginForm.value.password);
            loginLoading.value = false;
            if (res.code === 200) {
                isAdmin.value = true;
                showLogin.value = false;
                loginForm.value = { user: '', password: '' };
                await loadMe();
                toast('登录成功');
            } else {
                toast(res.message || '登录失败', 'error');
            }
        }

        async function logout() {
            await api.logout();
            isAdmin.value = false;
            user.value = {};
            showUserMenu.value = false;
            toast('已退出');
        }

        async function loadMe() {
            const res = await api.me();
            if (res.code === 200) {
                user.value = res.data;
                stats.value.total_images = res.data.total_images;
                stats.value.daily_uploads = res.data.daily_uploads;
                stats.value.total_size = res.data.total_size;
                if (res.data.api_key) {
                    try { localStorage.setItem("image_api_key", res.data.api_key); } catch (e) {}
                }
            }
        }

        // Upload
        function handleFileSelect(e) {
            const files = Array.from(e.target.files || []);
            if (files.length) uploadFiles(files);
        }

        function handleDrop(e) {
            dragover.value = false;
            const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
            if (files.length) uploadFiles(files);
        }

        async function handlePaste(e) {
            const items = e.clipboardData?.items;
            if (!items) return;
            const files = [];
            for (const item of items) {
                if (item.type.startsWith('image/')) {
                    const file = item.getAsFile();
                    if (file) files.push(file);
                }
            }
            if (files.length) {
                e.preventDefault();
                await uploadFiles(files);
            }
        }

        async function uploadFiles(files) {
            for (const file of files) {
                const task = { name: file.name, progress: 0 };
                uploading.value.push(task);

                // Simulate progress
                const progressTimer = setInterval(() => {
                    if (task.progress < 90) task.progress += Math.random() * 15;
                }, 200);

                const res = await api.upload(file, {
                    convert: settings.value.convertWebp ? 'webp' : '',
                });

                clearInterval(progressTimer);
                task.progress = 100;
                uploading.value = uploading.value.filter(t => t !== task);

                if (res.code === 200) {
                    const item = { ...res.data, links: res.data.links };
                    results.value.unshift(item);
                    stats.value.total_images++;
                    stats.value.daily_uploads++;
                    stats.value.total_size += item.size;

                    if (settings.value.autoCopy) {
                        await copy(item.links[settings.value.defaultFormat]);
                    }
                } else {
                    toast(res.message || '上传失败', 'error');
                }
            }
        }

        // Copy
        async function copy(text) {
            const ok = await copyText(text);
            toast(ok ? '已复制到剪贴板' : '复制失败', ok ? 'success' : 'error');
        }

        // History
        async function loadHistory(page = 1) {
            loading.value = true;
            const res = await api.listImages(page);
            loading.value = false;
            if (res.code === 200) {
                history.value = res.data.items;
                pagination.value = res.data.pagination;
            }
        }

        watch(view, (newView) => {
            if (newView === 'history') {
                selectedImages.value = [];
                loadHistory(1);
            }
            if (newView === 'admin' || newView === 'api') {
                if (isAdmin.value) loadMe();
            }
        });

        // Delete
        async function deleteImage(id, from) {
            if (!confirm('确定删除这张图片吗？')) return;
            const res = await api.deleteImage(id);
            if (res.code === 200) {
                if (from === 'results') {
                    const img = results.value.find(i => i.id === id);
                    if (img) stats.value.total_size -= img.size;
                    results.value = results.value.filter(i => i.id !== id);
                } else {
                    loadHistory(pagination.value.page);
                }
                stats.value.total_images--;
                toast('删除成功');
            } else {
                toast(res.message || '删除失败', 'error');
            }
        }

        async function batchDelete() {
            if (!selectedImages.value.length) return;
            if (!confirm(`确定删除选中的 ${selectedImages.value.length} 张图片吗？`)) return;
            for (const id of selectedImages.value) {
                await api.deleteImage(id);
            }
            selectedImages.value = [];
            await loadHistory(pagination.value.page);
            await loadMe();
            toast('批量删除成功');
        }

        async function batchCopy() {
            const links = history.value
                .filter(i => selectedImages.value.includes(i.id))
                .map(i => i.links.url)
                .join('\n');
            await copy(links);
        }

        // Preview
        function preview(item) {
            previewImage.value = item;
        }

        // API docs
        const apiEndpoints = computed(() => {
            const base = window.location.origin;
            const key = user.value.api_key || 'YOUR_API_KEY';
            return [
                {
                    title: '上传图片',
                    method: 'POST',
                    methodClass: 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
                    url: `${base}/api/v1/upload.php`,
                    desc: '上传图片文件，支持 form-data 的 file 字段',
                    example: `curl -X POST "${base}/api/v1/upload.php" \\\n  -F "token=${key}" \\\n  -F "file=@/path/to/image.jpg"`,
                },
                {
                    title: '获取图片列表',
                    method: 'GET',
                    methodClass: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
                    url: `${base}/api/v1/images.php`,
                    desc: '分页获取上传历史',
                    example: `curl -X GET "${base}/api/v1/images.php?page=1&limit=20"`,
                },
                {
                    title: '删除图片',
                    method: 'DELETE',
                    methodClass: 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
                    url: `${base}/api/v1/images.php?id={id}`,
                    desc: '根据图片 ID 删除图片',
                    example: `curl -X DELETE "${base}/api/v1/images.php?id=xxx"`,
                },
            ];
        });

        onMounted(() => {
            initTheme();
            checkAuth();
            document.addEventListener('paste', handlePaste);
        });

        return {
            view, dark, isAdmin, user, stats,
            showLogin, showUserMenu, showSettings,
            dragover, loading, toasts, fileInput,
            loginForm, loginLoading,
            settings, uploading, results, resultFormat,
            history, pagination, selectedImages, previewImage,
            uploadProgress, formats, apiEndpoints,
            toggleTheme, login, logout, checkAuth, loadMe,
            handleFileSelect, handleDrop, handlePaste, uploadFiles,
            copy, loadHistory, deleteImage, batchDelete, batchCopy,
            preview, formatSize, formatDate,
        };
    },
}).mount('#app');
