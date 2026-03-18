const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const sidebar       = document.getElementById('sidebar');
const overlay       = document.getElementById('overlay');

mobileMenuBtn.addEventListener('click', () => {
    sidebar.classList.toggle('-translate-x-full');
    overlay.classList.toggle('hidden');
});

overlay.addEventListener('click', () => {
    sidebar.classList.add('-translate-x-full');
    overlay.classList.add('hidden');
});