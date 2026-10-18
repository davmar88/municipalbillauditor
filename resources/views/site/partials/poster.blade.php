{{--
    The poster at the top of the front page: a storm breaking into a golden
    sunset over the farm, a pig on the hay bales under the green flag with
    the hoof and horn, horses either side, a cow, sheep and hens, the barn
    on the left and the windmill on the right. Drawn here, so it stays sharp
    at any size and weighs next to nothing.
--}}
<svg class="h-full w-full" viewBox="0 0 1600 900" preserveAspectRatio="xMidYMax slice" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="poster-sky" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#0f0b17" />
            <stop offset="0.32" stop-color="#271a31" />
            <stop offset="0.55" stop-color="#663737" />
            <stop offset="0.68" stop-color="#c8722c" />
            <stop offset="0.76" stop-color="#f3b048" />
            <stop offset="0.82" stop-color="#ffd981" />
        </linearGradient>
        <radialGradient id="poster-sun" cx="980" cy="705" r="560" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="#fff4c4" stop-opacity="0.95" />
            <stop offset="0.25" stop-color="#ffc463" stop-opacity="0.6" />
            <stop offset="0.6" stop-color="#e2803a" stop-opacity="0.18" />
            <stop offset="1" stop-color="#e2803a" stop-opacity="0" />
        </radialGradient>
        <linearGradient id="poster-bale" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#e9b955" />
            <stop offset="1" stop-color="#9c6b1f" />
        </linearGradient>
        <linearGradient id="poster-pig" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#f7cdbf" />
            <stop offset="1" stop-color="#b76c6f" />
        </linearGradient>
        <linearGradient id="poster-flag" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#2d7442" />
            <stop offset="0.5" stop-color="#3a8c52" />
            <stop offset="1" stop-color="#245e36" />
        </linearGradient>
        <linearGradient id="poster-ground" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#2a1b18" />
            <stop offset="1" stop-color="#0d0909" />
        </linearGradient>
        <radialGradient id="poster-vignette" cx="0.5" cy="0.48" r="0.78">
            <stop offset="0.5" stop-color="#000" stop-opacity="0" />
            <stop offset="1" stop-color="#000" stop-opacity="0.72" />
        </radialGradient>
        <filter id="poster-grain" x="0" y="0" width="100%" height="100%">
            <feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="2" stitchTiles="stitch" />
            <feColorMatrix type="saturate" values="0" />
        </filter>
    </defs>

    {{-- Sky, sun and storm --}}
    <rect width="1600" height="900" fill="url(#poster-sky)" />
    <rect width="1600" height="900" fill="url(#poster-sun)" />
    <g fill="#d27a3a" opacity="0.35">
        <ellipse cx="260" cy="190" rx="260" ry="70" />
        <ellipse cx="520" cy="140" rx="220" ry="62" />
        <ellipse cx="860" cy="210" rx="300" ry="64" />
        <ellipse cx="1250" cy="150" rx="280" ry="70" />
        <ellipse cx="1500" cy="250" rx="200" ry="56" />
        <ellipse cx="640" cy="330" rx="260" ry="40" />
        <ellipse cx="1160" cy="360" rx="240" ry="36" />
    </g>
    <g fill="#0d0a13" opacity="0.92">
        <ellipse cx="250" cy="176" rx="270" ry="72" />
        <ellipse cx="90" cy="110" rx="200" ry="90" />
        <ellipse cx="520" cy="124" rx="230" ry="66" />
        <ellipse cx="860" cy="194" rx="310" ry="66" />
        <ellipse cx="760" cy="70" rx="330" ry="90" />
        <ellipse cx="1250" cy="134" rx="290" ry="72" />
        <ellipse cx="1480" cy="80" rx="240" ry="96" />
        <ellipse cx="1500" cy="236" rx="210" ry="58" />
        <ellipse cx="640" cy="318" rx="270" ry="42" opacity="0.8" />
        <ellipse cx="1160" cy="348" rx="250" ry="38" opacity="0.8" />
    </g>

    {{-- Far hills, the windmill and the farm on the horizon --}}
    <path d="M0 700 C160 660 300 690 440 668 C600 642 720 682 880 664 C1040 646 1180 676 1320 656 C1440 640 1530 662 1600 650 L1600 900 L0 900 Z" fill="#3b2537" opacity="0.92" />
    <g fill="#1d1520">
        <path d="M1150 700 L1150 668 L1180 648 L1210 668 L1210 700 Z" />
        <path d="M1215 700 L1215 676 L1262 676 L1262 700 Z" />
        <path d="M1212 678 L1238 660 L1265 678 Z" />
        <path d="M1272 700 L1272 664 L1300 644 L1328 664 L1328 700 Z" />
        <path d="M1472 700 L1486 520 L1514 520 L1528 700 Z" />
        <path d="M1480 524 L1500 500 L1520 524 Z" />
        <g transform="translate(1500 512)">
            <rect x="-6" y="-170" width="12" height="170" transform="rotate(18)" />
            <rect x="-6" y="-170" width="12" height="170" transform="rotate(108)" />
            <rect x="-6" y="-170" width="12" height="170" transform="rotate(198)" />
            <rect x="-6" y="-170" width="12" height="170" transform="rotate(288)" />
            <circle r="10" />
        </g>
    </g>

    {{-- Fields catching the last light --}}
    <path d="M0 742 C200 716 380 736 560 724 C760 710 940 732 1120 720 C1300 708 1460 728 1600 716 L1600 900 L0 900 Z" fill="#2c1c19" />
    <path d="M0 742 C200 716 380 736 560 724 C760 710 940 732 1120 720 C1300 708 1460 728 1600 716" fill="none" stroke="#f0a14a" stroke-opacity="0.45" stroke-width="3" />

    {{-- The barn --}}
    <g>
        <path d="M96 776 L96 590 L150 486 L246 444 L342 486 L398 590 L398 776 Z" fill="#3b1714" />
        <path d="M80 596 L150 480 L246 436 L342 480 L414 596 L398 604 L334 494 L246 456 L158 494 L96 604 Z" fill="#240d0b" />
        <path d="M246 436 L342 480 L414 596" fill="none" stroke="#f3a54c" stroke-opacity="0.55" stroke-width="3" />
        <rect x="206" y="660" width="82" height="116" fill="#2a0f0c" />
        <path d="M206 660 L288 776 M288 660 L206 776" stroke="#5c2a20" stroke-width="5" />
        <rect x="226" y="520" width="40" height="38" fill="#ffcf6e" opacity="0.8" />
        <path d="M398 590 L398 776" stroke="#f3a54c" stroke-opacity="0.4" stroke-width="3" />
    </g>

    <g transform="translate(260 0)">
    {{-- The flag on its pole, behind the bales --}}
    <rect x="696" y="232" width="7" height="520" fill="#241a1d" />
    <circle cx="699.5" cy="230" r="7" fill="#e7b85a" />
    <path d="M703 244 C760 236 830 258 906 248 L884 274 L912 298 L878 316 L904 340 L846 334 C800 346 750 330 703 338 Z" fill="url(#poster-flag)" />
    <path d="M760 250 C770 280 772 310 766 334 M840 252 C846 282 846 308 842 332" stroke="#1c4a2a" stroke-opacity="0.5" stroke-width="4" fill="none" />
    {{-- The hoof and the horn --}}
    <g fill="#f3e6c4">
        <path d="M770 318 C764 296 774 278 798 270 C788 284 786 300 792 318 Z" />
        <path d="M816 318 C812 302 816 288 830 284 L832 318 Z" />
        <path d="M836 318 L838 284 C852 288 856 302 852 318 Z" />
    </g>

    {{-- Hay bales, three high --}}
    <g stroke="#7b5213" stroke-width="3">
        @foreach ([[620, 704], [740, 704], [860, 704], [680, 648], [800, 648], [740, 592]] as [$x, $y])
            <rect x="{{ $x }}" y="{{ $y }}" width="120" height="56" rx="6" fill="url(#poster-bale)" />
            <path d="M{{ $x + 30 }} {{ $y + 2 }} V{{ $y + 54 }} M{{ $x + 90 }} {{ $y + 2 }} V{{ $y + 54 }}" stroke="#6b4510" stroke-width="4" />
            <path d="M{{ $x + 8 }} {{ $y + 16 }} H{{ $x + 112 }} M{{ $x + 8 }} {{ $y + 32 }} H{{ $x + 112 }} M{{ $x + 8 }} {{ $y + 44 }} H{{ $x + 112 }}" stroke="#b98a35" stroke-opacity="0.55" stroke-width="2" />
        @endforeach
    </g>

    {{-- The pig on top --}}
    <g>
        <path d="M744 538 C728 528 724 548 738 552" fill="none" stroke="#c98386" stroke-width="5" stroke-linecap="round" />
        <rect x="764" y="560" width="14" height="34" rx="5" fill="#b26a6d" />
        <rect x="786" y="562" width="14" height="32" rx="5" fill="#a65f63" />
        <rect x="822" y="562" width="14" height="32" rx="5" fill="#b26a6d" />
        <rect x="842" y="560" width="14" height="34" rx="5" fill="#a65f63" />
        <ellipse cx="800" cy="540" rx="66" ry="40" fill="url(#poster-pig)" />
        <circle cx="862" cy="524" r="28" fill="url(#poster-pig)" />
        <path d="M846 500 L858 478 L868 502 Z" fill="#c8797c" />
        <path d="M866 498 L882 480 L886 506 Z" fill="#b76c6f" />
        <ellipse cx="889" cy="532" rx="12" ry="10" fill="#e6a2a0" />
        <circle cx="885" cy="531" r="2.2" fill="#6b3436" />
        <circle cx="893" cy="531" r="2.2" fill="#6b3436" />
        <circle cx="868" cy="516" r="3" fill="#2a1414" />
        <path d="M740 520 C760 500 820 494 856 500" fill="none" stroke="#ffe0b0" stroke-opacity="0.6" stroke-width="3" />
    </g>

    </g>

    <g transform="translate(230 0)">
    {{-- The dark horse on the left --}}
    <g fill="#120d10">
        <path d="M432 700 C420 720 412 750 420 772" fill="none" stroke="#120d10" stroke-width="12" stroke-linecap="round" />
        <ellipse cx="510" cy="690" rx="84" ry="38" />
        <rect x="446" y="700" width="14" height="80" rx="5" />
        <rect x="472" y="704" width="14" height="76" rx="5" />
        <rect x="534" y="704" width="14" height="76" rx="5" />
        <rect x="560" y="700" width="14" height="80" rx="5" />
        <path d="M556 676 C572 640 588 612 600 598 L622 612 C606 632 596 662 588 700 Z" />
        <ellipse cx="620" cy="604" rx="30" ry="15" transform="rotate(32 620 604)" />
        <path d="M596 596 L602 578 L610 594 Z" />
        <path d="M578 640 C590 612 600 600 604 594" fill="none" stroke="#2a1d20" stroke-width="10" />
    </g>
    <path d="M436 666 C470 650 540 650 576 660 C590 628 604 606 622 592" fill="none" stroke="#f2a24a" stroke-opacity="0.5" stroke-width="3" />

    </g>

    <g transform="translate(190 0)">
    {{-- The brown horse in harness on the right --}}
    <g>
        <path d="M1186 702 C1200 722 1206 750 1200 772" fill="none" stroke="#4a2716" stroke-width="12" stroke-linecap="round" />
        <rect x="1036" y="708" width="14" height="74" rx="5" fill="#4f2a17" />
        <rect x="1062" y="712" width="14" height="70" rx="5" fill="#5a3019" />
        <rect x="1128" y="712" width="14" height="70" rx="5" fill="#4f2a17" />
        <rect x="1154" y="708" width="14" height="74" rx="5" fill="#5a3019" />
        <ellipse cx="1100" cy="696" rx="88" ry="40" fill="#6e3d22" />
        <path d="M1050 684 C1034 648 1018 622 1006 608 L984 622 C1000 642 1010 670 1018 708 Z" fill="#6e3d22" />
        <ellipse cx="986" cy="614" rx="30" ry="15" transform="rotate(-32 986 614)" fill="#6e3d22" />
        <path d="M1010 606 L1004 588 L996 604 Z" fill="#5a3019" />
        <ellipse cx="1036" cy="672" rx="18" ry="30" fill="none" stroke="#2b1a12" stroke-width="8" />
        <path d="M1046 690 H1180 M1060 716 C1100 726 1150 724 1180 712" fill="none" stroke="#2b1a12" stroke-width="5" />
        <path d="M1012 662 C1060 652 1140 652 1184 668" fill="none" stroke="#f2a24a" stroke-opacity="0.45" stroke-width="3" />
    </g>

    </g>

    <g transform="translate(160 0)">
    {{-- The cow on the far right --}}
    <g>
        <rect x="1262" y="722" width="13" height="58" rx="5" fill="#2a2124" />
        <rect x="1288" y="724" width="13" height="56" rx="5" fill="#2a2124" />
        <rect x="1386" y="724" width="13" height="56" rx="5" fill="#2a2124" />
        <rect x="1410" y="722" width="13" height="58" rx="5" fill="#2a2124" />
        <rect x="1252" y="660" width="184" height="76" rx="34" fill="#efe9dc" />
        <path d="M1290 664 C1320 676 1330 706 1306 732 L1280 734 C1268 706 1270 680 1290 664 Z" fill="#151012" />
        <path d="M1360 662 C1392 668 1410 690 1398 714 C1380 726 1356 712 1352 690 Z" fill="#151012" />
        <rect x="1214" y="650" width="52" height="40" rx="18" fill="#efe9dc" />
        <ellipse cx="1220" cy="678" rx="14" ry="11" fill="#d9a6a0" />
        <path d="M1226 652 L1214 636 M1250 652 L1262 636" stroke="#e9dcc0" stroke-width="5" stroke-linecap="round" />
        <path d="M1436 680 C1450 700 1452 724 1446 744" fill="none" stroke="#efe9dc" stroke-width="5" stroke-linecap="round" />
    </g>

    </g>

    {{-- Foreground: the field, sheep and hens --}}
    <path d="M0 780 C240 768 480 790 760 778 C1040 766 1300 786 1600 774 L1600 900 L0 900 Z" fill="url(#poster-ground)" />
    <g stroke="#3b3a22" stroke-width="2" stroke-linecap="round" opacity="0.7">
        @foreach (range(0, 79) as $i)
            @php($gx = $i * 20 + ($i % 3) * 5)
            @php($gy = 800 + ($i * 37) % 90)
            <path d="M{{ $gx }} {{ $gy }} l{{ ($i % 2) ? 3 : -3 }} -14" />
        @endforeach
    </g>
    @foreach ([[620, 830, 1], [740, 848, 0.9], [1270, 838, 1], [1390, 858, 0.85]] as [$sx, $sy, $s])
        <g transform="translate({{ $sx }} {{ $sy }}) scale({{ $s }})">
            <rect x="-26" y="10" width="8" height="22" rx="3" fill="#1d1618" />
            <rect x="14" y="10" width="8" height="22" rx="3" fill="#1d1618" />
            <g fill="#e9e1cf">
                <circle cx="-22" cy="0" r="18" />
                <circle cx="0" cy="-8" r="20" />
                <circle cx="22" cy="0" r="18" />
                <circle cx="0" cy="8" r="20" />
            </g>
            <ellipse cx="44" cy="-2" rx="13" ry="10" fill="#1d1618" />
            <path d="M-28 -16 C-10 -26 14 -26 30 -14" fill="none" stroke="#fff3cf" stroke-opacity="0.7" stroke-width="3" />
        </g>
    @endforeach
    @foreach ([[840, 860, '#c98a44'], [892, 872, '#f2efe6'], [1190, 868, '#b8743a']] as [$hx, $hy, $colour])
        <g transform="translate({{ $hx }} {{ $hy }})">
            <path d="M-4 10 L-6 22 M4 10 L6 22" stroke="#d9a33c" stroke-width="3" />
            <ellipse cx="0" cy="0" rx="18" ry="13" fill="{{ $colour }}" />
            <circle cx="16" cy="-10" r="8" fill="{{ $colour }}" />
            <path d="M14 -18 L18 -24 L21 -17 Z" fill="#d13b2f" />
            <path d="M23 -10 L30 -8 L23 -6 Z" fill="#e9b53a" />
            <path d="M-18 -4 C-26 -14 -22 -22 -14 -20" fill="{{ $colour }}" />
        </g>
    @endforeach

    {{-- The old film look: grain and dark edges --}}
    <rect width="1600" height="900" filter="url(#poster-grain)" opacity="0.07" />
    <rect width="1600" height="900" fill="url(#poster-vignette)" />
</svg>
