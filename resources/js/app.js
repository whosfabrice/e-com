import './bootstrap';

const themeStorageKey = 'coredrive-theme';
const root = document.documentElement;
const toggle = document.getElementById('theme-toggle');

const applyTheme = (theme) => {
    root.dataset.theme = theme;
};

const preferredTheme = () => {
    const storedTheme = window.localStorage.getItem(themeStorageKey);

    if (storedTheme === 'light' || storedTheme === 'dark') {
        return storedTheme;
    }

    return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
};

applyTheme(preferredTheme());

if (toggle) {
    toggle.addEventListener('click', () => {
        const nextTheme = root.dataset.theme === 'light' ? 'dark' : 'light';

        applyTheme(nextTheme);
        window.localStorage.setItem(themeStorageKey, nextTheme);
    });
}
