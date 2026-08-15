<?php

/*
|--------------------------------------------------------------------------
| Legacy text corrections
|--------------------------------------------------------------------------
|
| A Latin-1 round-trip in the old admin destroyed the Romanian characters that
| do not exist in Latin-1 — ă, ș, ț — replacing each with a literal "?" byte
| (0x3F). Characters that do exist there (â, î) survived. The damage is in the
| source database and is served by the live site today, so it cannot be decoded
| back; the text has to be retyped.
|
| Only three fields across the whole dataset are affected. Each entry is applied
| by ImportLegacyCommand only when the stored value still shows the corruption,
| so a fixed upstream value is never overwritten.
|
| Scope note: these restore the "?" characters only. Plain misspellings in the
| same rows ("întalniri", "Casa Iiris") are left as the venue wrote them.
|
*/

return [
    57 => [
        'description' => <<<'HTML'
            Vino în Restaurant Casa Iris și vei avea parte de un ambient plăcut, servicii impecabile, prețuri atractive și cele mai gustoase preparate din oraș.<br />
            <br />
            La Restaurant Casa Iris găsiți atmosfera ideală pentru întalniri de afaceri, ceremonii, aniversări, cine romantice într-un cadru intim !<br />
            <br />
            Combinație perfectă între clasic și modern, Casa Iiris este locul în care vei putea asculta întotdeauna muzică pe gustul tău și vei putea alege din multitudinea de preparate tradiționale și internaționale.<br />
            HTML,
    ],

    79 => [
        'description' => <<<'HTML'
            <p>
            Hotelul XE-MAR din Arad, a fost construit și inaugurat &icirc;n 2006, de două surori Xenia si Mariana, de unde provine și numele hotelului.</p>
            <p>
            Hotelul XE-MAR este situat &icirc;n apropierea podului Traian(1902) de peste r&acirc;ul Mureș, vechea graniță care desparte Banatul de Crișana, leg&acirc;nd Aradul de Aradul Nou, centru vechi istoric.</p>
            HTML,
    ],

    94 => [
        'address' => 'Str. Piața Victoriei, Nr. 2',
    ],
];
