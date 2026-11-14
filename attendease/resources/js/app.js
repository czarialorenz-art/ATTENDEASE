// Small progressive enhancements shared by every page.

// <button data-copy="#selector"> copies the target's text.
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');
    if (!button) return;

    const target = document.querySelector(button.dataset.copy);
    if (!target) return;

    const text = target.textContent.trim();
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        const range = document.createRange();
        range.selectNodeContents(target);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        document.execCommand('copy');
    }

    const label = button.textContent;
    button.textContent = 'Copied!';
    setTimeout(() => (button.textContent = label), 1500);
});

// <button data-toggle-password="inputId"> shows or hides a password.
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-toggle-password]');
    if (!button) return;

    const input = document.getElementById(button.dataset.togglePassword);
    if (!input) return;

    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(show));
    button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    button.querySelector('[data-label]').textContent = show ? 'Hide' : 'Show';
    button.querySelector('[data-eye]').classList.toggle('hidden', show);
    button.querySelector('[data-eye-off]').classList.toggle('hidden', !show);
    input.focus();
});

// <input data-password-rules="listId"> ticks off each rule as the user types.
// Mirrors Password::defaults() in AppServiceProvider; the server still checks everything.
const PASSWORD_RULES = {
    length: (v) => v.length >= 8,
    lower: (v) => /\p{Ll}/u.test(v),
    upper: (v) => /\p{Lu}/u.test(v),
    number: (v) => /\p{N}/u.test(v),
    symbol: (v) => /[\p{Z}\p{S}\p{P}]/u.test(v),
};

function checkPassword(input) {
    const list = document.getElementById(input.dataset.passwordRules);
    if (!list) return;

    list.querySelectorAll('[data-rule]').forEach((item) => {
        const ok = PASSWORD_RULES[item.dataset.rule]?.(input.value) ?? false;
        const mark = item.querySelector('[data-mark]');
        item.classList.toggle('text-emerald-700', ok);
        item.classList.toggle('text-slate-500', !ok);
        mark.textContent = ok ? '✓' : '';
        mark.classList.toggle('border-emerald-600', ok);
        mark.classList.toggle('bg-emerald-600', ok);
        mark.classList.toggle('text-white', ok);
        mark.classList.toggle('border-slate-300', !ok);
    });
}

document.addEventListener('input', (event) => {
    if (event.target.matches?.('[data-password-rules]')) checkPassword(event.target);
});

// <details data-menu> dropdowns close when clicking elsewhere or pressing Escape, and only one stays open.
document.addEventListener('click', (event) => {
    document.querySelectorAll('details[data-menu][open]').forEach((menu) => {
        if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
});
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('details[data-menu][open]').forEach((menu) => {
        menu.removeAttribute('open');
        menu.querySelector('summary')?.focus();
    });
});

// <form data-confirm="..."> asks before submitting.
document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
});
