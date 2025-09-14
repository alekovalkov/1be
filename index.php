<?php
$lang = $_GET['lang'] ?? 'et';
$available = ['et','en','uk','ru'];
if (!in_array($lang, $available)) {
    $lang = 'et';
}
$translations = include __DIR__ . '/lang/' . $lang . '.php';
function t(string $key): string {
    global $translations;
    return $translations[$key] ?? $key;
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maniküür.ee</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #ffe6f2, #ffffff);
            color: #333;
            opacity: 0;
            animation: fadeIn 1s forwards;
        }
        .header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 20px;
            background: rgba(255,255,255,0.9);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #d63384;
        }
        nav a {
            margin: 0 10px;
            color: #333;
            text-decoration: none;
        }
        nav a:hover {
            color: #d63384;
        }
        nav select {
            padding: 5px;
            border-radius: 5px;
        }
        .hero {
            margin-top: 80px;
            text-align: center;
            padding: 80px 20px;
        }
        .hero .btn {
            margin-top: 20px;
            display: inline-block;
            padding: 12px 30px;
            background: #d63384;
            color: #fff;
            border-radius: 25px;
            text-decoration: none;
            transition: background 0.3s;
        }
        .hero .btn:hover {
            background: #b5176a;
        }
        .privileges, .portfolio, .reviews {
            padding: 60px 20px;
            text-align: center;
        }
        .cards {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
        }
        .card {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            width: 250px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .card .icon {
            font-size: 30px;
            display: block;
            margin-bottom: 10px;
            color: #d63384;
        }
        .slider {
            position: relative;
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
        }
        .slide {
            display: none;
        }
        .slide.active {
            display: block;
        }
        .slide img {
            width: 100%;
            border-radius: 10px;
        }
        .portfolio button {
            margin: 10px;
            padding: 10px 20px;
            border: none;
            background: #d63384;
            color: #fff;
            border-radius: 5px;
            cursor: pointer;
        }
        .portfolio button:hover {
            background: #b5176a;
        }
        .review-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        .review {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        footer {
            padding: 40px 20px;
            text-align: center;
            background: #fce4ec;
        }
        footer .social a {
            margin: 0 10px;
            color: #d63384;
            font-size: 24px;
            text-decoration: none;
        }
        footer .copy {
            margin-top: 20px;
            color: #666;
        }
        #toTop {
            position: fixed;
            bottom: 40px;
            right: 40px;
            padding: 10px;
            border: none;
            border-radius: 50%;
            background: #d63384;
            color: #fff;
            cursor: pointer;
            display: none;
        }
        #toTop.show {
            display: block;
        }
        .fade-section {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.6s ease-out, transform 0.6s ease-out;
        }
        .fade-section.visible {
            opacity: 1;
            transform: translateY(0);
        }
        @keyframes fadeIn {
            from {opacity: 0;}
            to {opacity: 1;}
        }
        @media (max-width: 600px) {
            .cards {
                flex-direction: column;
                align-items: center;
            }
            .card {
                width: 100%;
                max-width: 300px;
            }
            nav a {
                margin: 0 5px;
            }
        }
    </style>
</head>
<body class="fade-in">
<header class="header">
    <div class="logo">Maniküür.ee</div>
    <nav>
        <a href="login.php"><?= t('login') ?></a>
        <a href="register.php"><?= t('register') ?></a>
        <select id="language-selector">
            <option value="et"<?= $lang === 'et' ? ' selected' : '' ?>>Est</option>
            <option value="en"<?= $lang === 'en' ? ' selected' : '' ?>>Eng</option>
            <option value="uk"<?= $lang === 'uk' ? ' selected' : '' ?>>Ukr</option>
            <option value="ru"<?= $lang === 'ru' ? ' selected' : '' ?>>Rus</option>
        </select>
    </nav>
</header>

<section class="hero fade-section">
    <h1><?= t('hero_text') ?></h1>
    <a href="#" class="btn"><?= t('book_online') ?></a>
</section>

<section class="privileges fade-section">
    <h2><?= t('our_privileges') ?></h2>
    <div class="cards">
        <div class="card"><span class="icon">🎁</span><p><?= t('loyal_points') ?></p></div>
        <div class="card"><span class="icon">💅</span><p><?= t('manicure_from') ?></p></div>
        <div class="card"><span class="icon">👩‍🔧</span><p><?= t('choose_master') ?></p></div>
        <div class="card"><span class="icon">⭐</span><p><?= t('experience') ?></p></div>
        <div class="card"><span class="icon">🧼</span><p><?= t('sterilization') ?></p></div>
        <div class="card"><span class="icon">🅿️</span><p><?= t('free_parking') ?></p></div>
        <div class="card"><span class="icon">🚆</span><p><?= t('public_transport') ?></p></div>
    </div>
</section>

<section class="portfolio fade-section">
    <h2><?= t('portfolio') ?></h2>
    <div class="slider">
        <div class="slide active"><img src="https://via.placeholder.com/600x400?text=Nails+1" alt=""></div>
        <div class="slide"><img src="https://via.placeholder.com/600x400?text=Nails+2" alt=""></div>
        <div class="slide"><img src="https://via.placeholder.com/600x400?text=Nails+3" alt=""></div>
    </div>
    <button class="prev"><?= t('prev') ?></button>
    <button class="next"><?= t('next') ?></button>
</section>

<section class="reviews fade-section">
    <h2><?= t('reviews') ?></h2>
    <div class="review-list">
        <div class="review"><?= t('review1') ?></div>
        <div class="review"><?= t('review2') ?></div>
        <div class="review"><?= t('review3') ?></div>
    </div>
</section>

<footer class="fade-section">
    <h2><?= t('contacts') ?></h2>
    <p><?= t('phone') ?>: +372 5555 5555</p>
    <p><?= t('email') ?>: info@manikuur.ee</p>
    <p><?= t('address') ?>: Tallinn, Estonia</p>
    <div class="social">
        <a href="#">📸</a>
        <a href="#">📘</a>
    </div>
    <p class="copy"><?= t('rights') ?></p>
</footer>

<button id="toTop">↑</button>

<script>
// language selector
const langSel = document.getElementById('language-selector');
langSel.addEventListener('change', function() {
    window.location = '?lang=' + this.value;
});

// slider
const slides = document.querySelectorAll('.slide');
let current = 0;
function showSlide(i) {
    slides.forEach((s, idx) => s.classList.toggle('active', idx === i));
}
document.querySelector('.next').addEventListener('click', () => {
    current = (current + 1) % slides.length;
    showSlide(current);
});
document.querySelector('.prev').addEventListener('click', () => {
    current = (current - 1 + slides.length) % slides.length;
    showSlide(current);
});
setInterval(() => {
    current = (current + 1) % slides.length;
    showSlide(current);
}, 3000);

// fade on scroll
const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('visible');
        }
    });
});
document.querySelectorAll('.fade-section').forEach(sec => observer.observe(sec));

// back to top
const toTop = document.getElementById('toTop');
window.addEventListener('scroll', () => {
    if (window.scrollY > 200) {
        toTop.classList.add('show');
    } else {
        toTop.classList.remove('show');
    }
});
toTop.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
});
</script>
</body>
</html>
