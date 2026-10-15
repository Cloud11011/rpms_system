document.querySelectorAll('[data-notice-template]').forEach(button => button.addEventListener('click', () => {
    const message = document.getElementById('noticeMessage');
    message.value = button.dataset.noticeTemplate;
    message.dispatchEvent(new Event('input', { bubbles: true }));
    message.focus();
}));
