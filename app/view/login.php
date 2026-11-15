<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#04162a">
    <meta name="robots" content="noindex,nofollow">
    <title>Entrar | YSTEK</title>
    <style>
        :root{
            --navy-950:#030f1d; --navy-900:#04182c; --navy-800:#06284a; --navy-700:#0a3a68;
            --blue:#1389ff; --blue-strong:#0b6cd9; --cyan:#27d3f0; --violet:#7c6cff;
            --ink:#0c2038; --muted:#5d7690; --soft:#8aa0b6; --line:#d6e2ee;
            --ok:#17a964; --warn:#e0a21a; --bad:#d64545;
            --radius:14px;
        }
        *{box-sizing:border-box}
        html,body{margin:0;min-height:100%}
        body{
            min-height:100vh;
            font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
            color:var(--ink); background:#eef5fc; -webkit-font-smoothing:antialiased;
        }
        button,input{font-family:inherit}
        .login-page{min-height:100vh;display:grid;grid-template-columns:minmax(0,1.18fr) minmax(440px,.82fr)}

        /* ============================ HERO ============================ */
        .hero{position:relative;overflow:hidden;color:#fff;background:var(--navy-950);isolation:isolate}
        .hero-photo{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center 30%;opacity:.30;mix-blend-mode:luminosity;filter:saturate(1.2) contrast(1.05);z-index:-4;transform:scale(1.04)}
        .hero::before{content:"";position:absolute;inset:0;z-index:-3;background:
            radial-gradient(900px 560px at 12% 8%,rgba(19,137,255,.38),transparent 62%),
            radial-gradient(700px 520px at 95% 40%,rgba(124,108,255,.26),transparent 62%),
            radial-gradient(800px 600px at 40% 110%,rgba(39,211,240,.20),transparent 60%),
            linear-gradient(160deg,#04182c 0%,#031120 60%,#020b16 100%)}
        .hero::after{content:"";position:absolute;inset:0;z-index:-2;pointer-events:none;
            background-image:linear-gradient(rgba(120,170,255,.07) 1px,transparent 1px),linear-gradient(90deg,rgba(120,170,255,.07) 1px,transparent 1px);
            background-size:46px 46px;-webkit-mask-image:radial-gradient(ellipse at 40% 40%,#000 20%,transparent 75%);mask-image:radial-gradient(ellipse at 40% 40%,#000 20%,transparent 75%)}
        .glow{position:absolute;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(420px circle at var(--mx,30%) var(--my,30%),rgba(70,160,255,.20),transparent 70%);transition:background .12s linear}
        .bubbles{position:absolute;inset:0;z-index:-1;overflow:hidden;pointer-events:none}
        .bubble{position:absolute;bottom:-60px;border-radius:50%;border:1px solid rgba(160,215,255,.38);background:radial-gradient(circle at 30% 28%,rgba(255,255,255,.35),rgba(255,255,255,.04) 45%,transparent 70%);animation:rise linear infinite;opacity:0}
        @keyframes rise{0%{transform:translate3d(0,0,0) scale(.7);opacity:0}12%{opacity:.9}100%{transform:translate3d(var(--dx,20px),-108vh,0) scale(1.05);opacity:0}}

        .hero-inner{min-height:100vh;padding:30px clamp(28px,4.4vw,72px) 24px;display:flex;flex-direction:column;gap:18px}
        .brand-lockup{display:flex;align-items:center;gap:20px}
        .brand-logo{width:150px;height:auto;display:block;filter:drop-shadow(0 6px 18px rgba(19,137,255,.35))}
        .brand-divider{width:1px;height:38px;background:linear-gradient(transparent,rgba(255,255,255,.35),transparent)}
        .brand-caption{font-size:11px;font-weight:800;letter-spacing:.26em;text-transform:uppercase;color:#b9d4f1}
        .hero-main{margin:auto 0;max-width:660px;width:100%}
        .hero-kicker{display:inline-flex;align-items:center;gap:9px;padding:7px 13px;border:1px solid rgba(110,175,255,.35);border-radius:999px;background:rgba(19,137,255,.12);color:#9fd0ff;font-size:11px;font-weight:800;letter-spacing:.16em;text-transform:uppercase;backdrop-filter:blur(6px)}
        .hero-kicker i{width:7px;height:7px;border-radius:50%;background:#35e0a1;box-shadow:0 0 0 0 rgba(53,224,161,.6);animation:ping 2s infinite}
        @keyframes ping{70%{box-shadow:0 0 0 9px rgba(53,224,161,0)}100%{box-shadow:0 0 0 0 rgba(53,224,161,0)}}
        .hero h1{margin:14px 0 12px;font-size:clamp(36px,4vw,58px);line-height:1.02;letter-spacing:-.035em;font-weight:850}
        .hero h1 span{background:linear-gradient(100deg,#4aa8ff 0%,#27d3f0 45%,#8f86ff 100%);background-size:200% 100%;-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;animation:flow 7s ease-in-out infinite alternate}
        @keyframes flow{to{background-position:100% 0}}
        .hero-lead{margin:0;max-width:540px;color:#bcd3ea;font-size:16px;line-height:1.6}

        /* -------- vitrine interativa -------- */
        .stage{margin-top:22px;perspective:1100px}
        .showcase{position:relative;border:1px solid rgba(140,190,255,.28);border-radius:18px;background:linear-gradient(160deg,rgba(14,42,76,.78),rgba(6,22,42,.82));backdrop-filter:blur(14px);box-shadow:0 30px 70px rgba(0,0,0,.45),0 0 0 1px rgba(255,255,255,.04) inset,0 0 60px rgba(19,137,255,.14);transform-style:preserve-3d;transition:transform .25s ease-out;will-change:transform;overflow:hidden}
        .sc-bar{display:flex;align-items:center;gap:12px;padding:11px 16px;border-bottom:1px solid rgba(140,190,255,.16);font-size:11px;color:#8fb3d6}
        .dots{display:flex;gap:6px}.dots i{width:9px;height:9px;border-radius:50%;background:#2c4a6c}.dots i:nth-child(1){background:#ff6b6b}.dots i:nth-child(2){background:#ffc14d}.dots i:nth-child(3){background:#46d68c}
        .sc-url{flex:1;text-align:center;letter-spacing:.04em}
        .sc-live{display:inline-flex;align-items:center;gap:6px;color:#9fd0ff;font-weight:700}
        .sc-live b{width:6px;height:6px;border-radius:50%;background:#35e0a1;animation:blink 1.6s infinite}
        @keyframes blink{50%{opacity:.25}}
        .sc-tabs{display:flex;gap:6px;padding:12px 14px 0}
        .sc-tab{flex:1;display:flex;align-items:center;justify-content:center;gap:8px;padding:10px 8px;border:1px solid transparent;border-radius:10px;background:transparent;color:#9db8d4;font-size:13px;font-weight:700;cursor:pointer;transition:all .2s ease}
        .sc-tab svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round;flex:0 0 16px}
        .sc-tab:hover{background:rgba(70,150,255,.12);color:#fff}
        .sc-tab[aria-selected="true"]{background:linear-gradient(135deg,rgba(19,137,255,.30),rgba(124,108,255,.22));border-color:rgba(120,185,255,.45);color:#fff;box-shadow:0 6px 18px rgba(19,137,255,.18)}
        .sc-tab:focus-visible{outline:2px solid #6cc0ff;outline-offset:2px}
        .sc-progress{height:2px;margin:12px 14px 0;border-radius:2px;background:rgba(140,190,255,.14);overflow:hidden}
        .sc-progress span{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--blue),var(--cyan));border-radius:2px}
        .sc-progress.run span{animation:fill var(--dur,6s) linear forwards}
        @keyframes fill{to{width:100%}}
        .sc-views{position:relative;min-height:232px;padding:14px 18px 16px}
        .view{position:absolute;inset:14px 18px 16px;opacity:0;visibility:hidden;transform:translateY(10px);transition:opacity .45s ease,transform .45s ease,visibility .45s}
        .view.active{opacity:1;visibility:visible;transform:none}
        .view-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:12px;color:#d6e6f7;font-size:13px;font-weight:700}
        .view-head small{color:#7f9fc0;font-weight:600;font-size:11px}

        /* agenda */
        .ag-row{display:grid;grid-template-columns:54px 1fr auto;gap:12px;align-items:center;padding:11px 12px;margin-bottom:8px;border:1px solid rgba(140,190,255,.14);border-radius:11px;background:rgba(255,255,255,.035);opacity:0;transform:translateX(-12px)}
        .view.active .ag-row{animation:slidein .5s ease forwards}
        .view.active .ag-row:nth-of-type(2){animation-delay:.08s}.view.active .ag-row:nth-of-type(3){animation-delay:.16s}.view.active .ag-row:nth-of-type(4){animation-delay:.24s}
        @keyframes slidein{to{opacity:1;transform:none}}
        .ag-time{font-size:13px;font-weight:800;color:#fff}
        .ag-main strong{display:block;font-size:13px;color:#eaf3ff}.ag-main span{font-size:11.5px;color:#86a5c6}
        .pill{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border:1px solid;border-radius:999px;font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;white-space:nowrap;transition:all .35s ease}
        .pill::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
        .st-agendado{color:#8cc4ff;background:rgba(19,137,255,.14);border-color:rgba(100,170,255,.45)}
        .st-aguardando{color:#ffd37a;background:rgba(255,193,77,.13);border-color:rgba(255,193,77,.45)}
        .st-servico{color:#6ee7f5;background:rgba(39,211,240,.13);border-color:rgba(39,211,240,.45)}
        .st-finalizado{color:#6df0b4;background:rgba(53,224,161,.13);border-color:rgba(53,224,161,.45)}

        /* check-in */
        .ck{display:grid;grid-template-columns:1.1fr 1fr;gap:18px;align-items:center}
        .ck-car{position:relative;border:1px solid rgba(140,190,255,.16);border-radius:12px;background:rgba(255,255,255,.03);padding:14px 10px}
        .ck-car svg{width:100%;height:auto;display:block}
        .ck-car .body{fill:rgba(60,140,255,.18);stroke:#5fb0ff;stroke-width:2}.ck-car .win{fill:rgba(140,210,255,.22);stroke:#7cc4ff;stroke-width:1.5}.ck-car .wheel{fill:#0a1f36;stroke:#5fb0ff;stroke-width:2.5}
        .mark{position:absolute;width:16px;height:16px;border-radius:50%;background:rgba(255,107,107,.9);border:2px solid #fff;box-shadow:0 0 0 0 rgba(255,107,107,.6);animation:ping2 2s infinite}
        @keyframes ping2{70%{box-shadow:0 0 0 12px rgba(255,107,107,0)}100%{box-shadow:0 0 0 0 rgba(255,107,107,0)}}
        .ck-fields{display:grid;gap:10px}
        .ck-line{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#9db8d4}.ck-line b{color:#fff;font-size:14px;font-variant-numeric:tabular-nums}
        .gauge{height:8px;border-radius:6px;background:rgba(140,190,255,.16);overflow:hidden}.gauge span{display:block;height:100%;width:0;border-radius:6px;background:linear-gradient(90deg,#ffb84d,#35e0a1);transition:width 1.4s cubic-bezier(.2,.8,.2,1)}
        .ck-items{display:grid;grid-template-columns:1fr 1fr;gap:7px 12px;margin-top:2px}
        .ck-item{display:flex;align-items:center;gap:7px;font-size:12px;color:#c5daf0;opacity:.35;transition:opacity .4s}
        .ck-item i{width:16px;height:16px;border-radius:5px;border:1.5px solid rgba(140,190,255,.5);display:grid;place-items:center;transition:all .3s}
        .ck-item i svg{width:11px;height:11px;stroke:#04182c;stroke-width:3;fill:none;stroke-linecap:round;stroke-linejoin:round;opacity:0}
        .ck-item.on{opacity:1}.ck-item.on i{background:#35e0a1;border-color:#35e0a1}.ck-item.on i svg{opacity:1}
        .chip-dmg{display:inline-flex;align-items:center;gap:7px;padding:6px 11px;border:1px solid rgba(255,107,107,.5);border-radius:999px;background:rgba(255,107,107,.12);color:#ffb0b0;font-size:11.5px;font-weight:700;width:max-content}

        /* financeiro */
        .fin-kpis{display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:10px;margin-bottom:14px}
        .kpi{padding:11px 13px;border:1px solid rgba(140,190,255,.16);border-radius:11px;background:rgba(255,255,255,.04)}
        .kpi span{display:block;font-size:10.5px;color:#86a5c6;letter-spacing:.06em;text-transform:uppercase;font-weight:700}
        .kpi b{display:block;margin-top:5px;font-size:21px;color:#fff;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
        .kpi.main b{background:linear-gradient(90deg,#fff,#9fd0ff);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
        .bars{display:flex;align-items:flex-end;gap:10px;height:104px;padding:0 4px}
        .bar{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end}
        .bar i{display:block;width:100%;height:0;border-radius:7px 7px 3px 3px;background:linear-gradient(180deg,#4aa8ff,#1b63c9);transition:height 1.1s cubic-bezier(.2,.8,.2,1)}
        .bar:last-child i{background:linear-gradient(180deg,#6df0b4,#1aa970);box-shadow:0 0 18px rgba(53,224,161,.35)}
        .bar small{font-size:10px;color:#7f9fc0}

        .sc-caption{margin-top:12px;min-height:44px;display:flex;flex-direction:column;gap:3px}
        .sc-caption strong{font-size:15px;color:#fff}.sc-caption span{font-size:13.5px;color:#a9c4de;line-height:1.5}
        .sc-caption.swap{animation:capin .5s ease}
        @keyframes capin{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
        .sc-note{margin-top:6px;font-size:10.5px;color:#6f8cab}
        .hero-foot{display:flex;justify-content:space-between;gap:12px;align-items:center;color:#7f9fc0;font-size:11.5px}
        .secure-note{display:inline-flex;align-items:center;gap:7px;color:#9fd0ff;font-weight:700}
        .secure-note svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}

        /* ============================ LOGIN ============================ */
        .login-side{position:relative;display:flex;align-items:center;justify-content:center;padding:44px clamp(22px,4vw,64px);overflow:hidden;background:
            radial-gradient(700px 420px at 100% 0%,rgba(19,137,255,.13),transparent 62%),
            radial-gradient(600px 420px at 0% 100%,rgba(124,108,255,.10),transparent 60%),
            linear-gradient(180deg,#f5f9ff,#e9f1fa)}
        .card{position:relative;width:100%;max-width:468px;padding:38px 36px 30px;border:1px solid rgba(255,255,255,.9);border-radius:24px;background:rgba(255,255,255,.88);backdrop-filter:blur(10px);box-shadow:0 30px 70px rgba(10,50,100,.16),0 4px 14px rgba(10,50,100,.06),0 0 0 1px rgba(150,185,225,.35);animation:cardin .7s cubic-bezier(.2,.8,.2,1) both}
        .card::before{content:"";position:absolute;left:36px;right:36px;top:-1px;height:3px;border-radius:0 0 4px 4px;background:linear-gradient(90deg,var(--blue),var(--cyan),var(--violet))}
        @keyframes cardin{from{opacity:0;transform:translateY(22px) scale(.985)}to{opacity:1;transform:none}}
        .card.shake{animation:cardin .7s cubic-bezier(.2,.8,.2,1) both,shake .5s .7s}
        @keyframes shake{20%,60%{transform:translateX(-7px)}40%,80%{transform:translateX(7px)}}
        .side-kicker{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;background:#e6f1ff;color:#0b64c8;font-size:11px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}
        .side-kicker svg{width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
        .login-title{margin:16px 0 8px;font-size:clamp(30px,3vw,38px);line-height:1.08;letter-spacing:-.03em;font-weight:850;color:var(--ink)}
        .login-copy{margin:0;color:var(--muted);font-size:14.5px;line-height:1.55}

        .reason-message,.server-message .alert{display:flex;align-items:flex-start;gap:10px;margin:20px 0 0;padding:13px 15px;border:1px solid;border-radius:12px;font-size:13.5px;line-height:1.45;font-weight:600;animation:msgin .45s ease both}
        @keyframes msgin{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
        .reason-message::before,.server-message .alert::before{content:"";flex:0 0 18px;height:18px;margin-top:1px;border-radius:50%;background:currentColor;-webkit-mask:var(--ic) center/contain no-repeat;mask:var(--ic) center/contain no-repeat}
        .reason-message.success{--ic:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm-1.2 14.6L6.5 12.3l1.4-1.4 2.9 2.9 5.3-5.3 1.4 1.4-6.7 6.7z'/%3E%3C/svg%3E");color:#0d7a48;background:#e8f8ef;border-color:#a9dfc3}
        .reason-message.error,.server-message .alert-error{--ic:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z'/%3E%3C/svg%3E");color:#a93131;background:#fff0ef;border-color:#f1bcb8}
        .server-message .alert-success{--ic:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm-1.2 14.6L6.5 12.3l1.4-1.4 2.9 2.9 5.3-5.3 1.4 1.4-6.7 6.7z'/%3E%3C/svg%3E");color:#0d7a48;background:#e8f8ef;border-color:#a9dfc3}
        .server-message .alert strong{font-weight:800}.server-message .close-btn{margin-left:auto;border:0;background:transparent;color:inherit;font-size:20px;line-height:1;cursor:pointer;opacity:.6}.server-message .close-btn:hover{opacity:1}

        .login-form{margin-top:24px;display:grid;gap:18px}
        .field label{display:block;margin-bottom:7px;color:#1a3652;font-size:12.5px;font-weight:800;letter-spacing:.01em}
        .input-wrap{position:relative}
        .input{width:100%;height:54px;padding:0 48px 0 46px;border:1.5px solid var(--line);border-radius:13px;background:#fff;color:var(--ink);font-size:15px;outline:none;transition:border-color .18s,box-shadow .18s,background .18s}
        .input::placeholder{color:#9db0c3}
        .input:hover{border-color:#b4c9de}
        .input:focus{border-color:var(--blue);box-shadow:0 0 0 4px rgba(19,137,255,.16)}
        .input-wrap .ico{position:absolute;left:15px;top:50%;transform:translateY(-50%);width:20px;height:20px;color:#8aa2ba;pointer-events:none;transition:color .18s}
        .input-wrap .ico svg{width:100%;height:100%;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
        .input-wrap:focus-within .ico{color:var(--blue)}
        .input-wrap.invalid .input{border-color:var(--bad);box-shadow:0 0 0 4px rgba(214,69,69,.12)}
        .input-wrap.valid .input{border-color:#7fd0a8}
        .tick{position:absolute;right:14px;top:50%;width:22px;height:22px;transform:translateY(-50%) scale(.4);opacity:0;border-radius:50%;background:var(--ok);display:grid;place-items:center;transition:all .25s cubic-bezier(.2,.9,.3,1.4);pointer-events:none}
        .tick svg{width:12px;height:12px;stroke:#fff;stroke-width:3;fill:none;stroke-linecap:round;stroke-linejoin:round}
        .input-wrap.valid .tick{opacity:1;transform:translateY(-50%) scale(1)}
        .local-error{display:none;margin-top:7px;color:var(--bad);font-size:12.5px;font-weight:700}
        .caps{display:none;align-items:center;gap:7px;margin-top:8px;color:#9a6a05;font-size:12.5px;font-weight:700}
        .caps.on{display:flex}
        .caps svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
        .password-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:38px;height:38px;display:grid;place-items:center;border:0;border-radius:10px;background:transparent;color:#6f88a1;cursor:pointer;transition:background .15s,color .15s}
        .password-toggle:hover{background:#edf5ff;color:var(--blue-strong)}
        .password-toggle:focus-visible{outline:2px solid var(--blue);outline-offset:1px}
        .password-toggle svg{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
        .password-toggle .eye-off{display:none}.password-toggle.shown .eye{display:none}.password-toggle.shown .eye-off{display:block}

        .submit{position:relative;overflow:hidden;width:100%;height:56px;margin-top:4px;display:flex;align-items:center;justify-content:center;gap:10px;border:0;border-radius:14px;background:linear-gradient(135deg,#1a93ff 0%,#0b66d6 60%,#5b4fe6 130%);background-size:140% 100%;color:#fff;font-size:15.5px;font-weight:800;letter-spacing:.01em;cursor:pointer;box-shadow:0 14px 28px rgba(11,102,214,.30),0 1px 0 rgba(255,255,255,.35) inset;transition:transform .12s ease,box-shadow .2s ease,background-position .4s ease}
        .submit::after{content:"";position:absolute;top:0;left:-60%;width:40%;height:100%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.35),transparent);transform:skewX(-20deg);transition:left .7s ease}
        .submit:hover{transform:translateY(-1px);background-position:100% 0;box-shadow:0 18px 34px rgba(11,102,214,.38),0 1px 0 rgba(255,255,255,.35) inset}
        .submit:hover::after{left:130%}
        .submit:active{transform:translateY(1px)}
        .submit:focus-visible{outline:3px solid rgba(19,137,255,.4);outline-offset:3px}
        .submit .arrow{width:18px;height:18px;stroke:#fff;fill:none;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round;transition:transform .2s}
        .submit:hover .arrow{transform:translateX(4px)}
        .submit .spinner{display:none;width:19px;height:19px;border:2.5px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}
        .submit.loading{cursor:wait;filter:saturate(.85)}.submit.loading .spinner{display:block}.submit.loading .arrow{display:none}

        .help{margin-top:18px;padding-top:16px;border-top:1px solid #e1ebf5;color:var(--muted);font-size:12.5px;line-height:1.55}
        .help strong{color:#26435f}
        .trust{display:flex;flex-wrap:wrap;gap:8px 16px;margin-top:14px}
        .trust span{display:inline-flex;align-items:center;gap:6px;color:#6b849d;font-size:11.5px;font-weight:600}
        .trust svg{width:14px;height:14px;stroke:#1a8d62;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
        .copyright{margin-top:14px;color:#8da1b5;font-size:11px}

        /* ========================= RESPONSIVO ========================= */
        @media(max-height:820px) and (min-width:981px){.hero-lead{display:none}.sc-note{display:none}.hero-inner{gap:12px}.brand-logo{width:120px}}
        @media(max-width:1180px){.sc-url{display:none}.fin-kpis{grid-template-columns:1fr 1fr}.fin-kpis .main{grid-column:1/-1}}
        @media(max-width:980px){
            .login-page{grid-template-columns:1fr}
            .hero-inner{min-height:0;padding:28px 26px 30px;gap:18px}
            .hero-main{margin:6px 0 0}
            .stage,.sc-note,.hero-foot{display:none}
            .hero-lead{font-size:14.5px}
            .login-side{padding:30px 18px 44px;margin-top:-26px}
            .card{max-width:520px}
        }
        @media(max-width:520px){
            .brand-logo{width:118px}.brand-divider,.brand-caption{display:none}
            .hero-kicker{font-size:10px;letter-spacing:.1em;padding:6px 11px}
            .hero h1{font-size:34px}.hero-lead{display:none}
            .card{padding:30px 22px 24px;border-radius:20px}.card::before{left:22px;right:22px}
            .login-title{font-size:28px}
        }
        @media(prefers-reduced-motion:reduce){
            *,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}
            .bubbles{display:none}
        }
    </style>
</head>
<body>
<main class="login-page">
    <section class="hero" id="hero" aria-label="YSTEK">
        <img class="hero-photo" src="public/img/login-hero-car.png" alt="">
        <div class="glow" aria-hidden="true"></div>
        <div class="bubbles" id="bubbles" aria-hidden="true"></div>
        <div class="hero-inner">
            <div class="brand-lockup">
                <img class="brand-logo" src="public/img/logo.png" alt="YSTEK Agency">
                <span class="brand-divider"></span>
                <span class="brand-caption">Sistemas</span>
            </div>

            <div class="hero-main">
                <div class="hero-kicker"><i></i>Plataforma de gestão para lava-jatos</div>
                <h1>Mais controle.<br><span>Mais resultado.</span></h1>
                <p class="hero-lead">Gerencie clientes, veículos, serviços, ordens e financeiro em um único ambiente, com acesso por empresa e perfil.</p>

                <div class="stage" aria-label="Pré-visualização do sistema">
                    <div class="showcase" id="showcase">
                        <div class="sc-bar">
                            <span class="dots"><i></i><i></i><i></i></span>
                            <span class="sc-url">ystek · painel do lava-jato</span>
                            <span class="sc-live"><b></b>Pré-visualização</span>
                        </div>
                        <div class="sc-tabs" role="tablist" aria-label="Recursos do sistema">
                            <button type="button" class="sc-tab" role="tab" aria-selected="true" data-i="0"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/></svg>Agenda</button>
                            <button type="button" class="sc-tab" role="tab" aria-selected="false" data-i="1"><svg viewBox="0 0 24 24"><path d="M9 11l2 2 4-4"/><rect x="4" y="4" width="16" height="17" rx="2"/></svg>Check-in</button>
                            <button type="button" class="sc-tab" role="tab" aria-selected="false" data-i="2"><svg viewBox="0 0 24 24"><path d="M5 20V10M12 20V4M19 20v-7M3 20h18"/></svg>Financeiro</button>
                        </div>
                        <div class="sc-progress" id="scProgress"><span></span></div>
                        <div class="sc-views">
                            <section class="view active" data-view="0" role="tabpanel">
                                <div class="view-head">Atendimentos de hoje <small>3 na fila</small></div>
                                <div class="ag-row"><span class="ag-time">09:00</span><div class="ag-main"><strong>Honda Civic · QWE4R56</strong><span>Lavagem completa</span></div><span class="pill st-agendado" id="liveStatus">Agendado</span></div>
                                <div class="ag-row"><span class="ag-time">10:30</span><div class="ag-main"><strong>Toyota Hilux · BRA2E19</strong><span>Polimento técnico</span></div><span class="pill st-servico">Em serviço</span></div>
                                <div class="ag-row"><span class="ag-time">11:15</span><div class="ag-main"><strong>VW Gol · JKL7M89</strong><span>Higienização interna</span></div><span class="pill st-aguardando">Aguardando</span></div>
                            </section>
                            <section class="view" data-view="1" role="tabpanel">
                                <div class="ck">
                                    <div class="ck-car">
                                        <svg viewBox="0 0 220 96" aria-hidden="true"><path class="body" d="M14 66c0-9 3-15 11-18l22-6 22-18c4-3 8-5 13-5h50c7 0 12 2 17 7l17 17 22 5c8 2 12 8 12 18v8c0 3-2 5-5 5h-10a22 22 0 0 0-44 0H92a22 22 0 0 0-44 0H19c-3 0-5-2-5-5z"/><path class="win" d="M70 38l14-12h42l16 14z"/><circle class="wheel" cx="70" cy="76" r="14"/><circle class="wheel" cx="156" cy="76" r="14"/></svg>
                                        <span class="mark" style="left:14%;top:56%"></span>
                                    </div>
                                    <div class="ck-fields">
                                        <div class="ck-line"><span>Quilometragem</span><b id="kmVal">0 km</b></div>
                                        <div><div class="ck-line" style="margin-bottom:6px"><span>Combustível</span><b id="fuelVal">0%</b></div><div class="gauge"><span id="fuelBar"></span></div></div>
                                        <div class="ck-items" id="ckItems">
                                            <div class="ck-item"><i><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></i>Chave recebida</div>
                                            <div class="ck-item"><i><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></i>Estepe</div>
                                            <div class="ck-item"><i><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></i>Macaco</div>
                                            <div class="ck-item"><i><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></i>Documentos</div>
                                        </div>
                                        <span class="chip-dmg">● 1 avaria registrada · para-choque</span>
                                    </div>
                                </div>
                            </section>
                            <section class="view" data-view="2" role="tabpanel">
                                <div class="fin-kpis">
                                    <div class="kpi main"><span>Faturamento hoje</span><b id="kpiRev">R$ 0,00</b></div>
                                    <div class="kpi"><span>O.S. concluídas</span><b id="kpiOs">0</b></div>
                                    <div class="kpi"><span>Ticket médio</span><b id="kpiTk">R$ 0</b></div>
                                </div>
                                <div class="bars" id="bars">
                                    <div class="bar"><i data-h="46"></i><small>Seg</small></div><div class="bar"><i data-h="62"></i><small>Ter</small></div><div class="bar"><i data-h="54"></i><small>Qua</small></div><div class="bar"><i data-h="78"></i><small>Qui</small></div><div class="bar"><i data-h="70"></i><small>Sex</small></div><div class="bar"><i data-h="92"></i><small>Sáb</small></div><div class="bar"><i data-h="100"></i><small>Hoje</small></div>
                                </div>
                            </section>
                        </div>
                    </div>
                    <div class="sc-caption" id="scCaption" aria-live="polite"><strong id="capTitle">Agenda e ordens</strong><span id="capText">Organize os horários e acompanhe cada veículo até a finalização.</span></div>
                    <div class="sc-note">Exemplo ilustrativo com dados fictícios.</div>
                </div>
            </div>

            <div class="hero-foot">
                <span>YSTEK · Plataforma de gestão</span>
                <span class="secure-note"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Ambiente seguro</span>
            </div>
        </div>
    </section>

    <section class="login-side">
        <div class="card" id="card">
            <div class="side-kicker"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Área restrita</div>
            <h2 class="login-title">Entrar na plataforma</h2>
            <p class="login-copy">Use suas credenciais corporativas para acessar o ambiente correspondente ao seu perfil.</p>

            <?php if (!empty($reasonMessage)): ?>
                <div class="reason-message <?= htmlspecialchars($reasonMessage['type'], ENT_QUOTES, 'UTF-8') ?>" role="status"><?= htmlspecialchars($reasonMessage['message'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="server-message" role="alert"><?= $serverMessage ?? '' ?></div>

            <form id="loginForm" class="login-form" method="POST" action="index.php?class=LoginController" autocomplete="off" novalidate><?= \App\Core\Security::loginCsrfInput() ?>
                <div class="field">
                    <label for="login_email">E-mail corporativo</label>
                    <div class="input-wrap" id="emailWrap">
                        <span class="ico"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg></span>
                        <input type="text" id="login_email" name="login_email" class="input" inputmode="email" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" placeholder="voce@suaempresa.com.br" maxlength="255">
                        <span class="tick"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></span>
                    </div>
                    <div class="local-error" id="emailError" role="alert">Informe um e-mail válido.</div>
                </div>

                <div class="field">
                    <label for="login_password">Senha</label>
                    <div class="input-wrap">
                        <span class="ico"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15" r="1"/></svg></span>
                        <input type="password" id="login_password" name="password" class="input" autocomplete="new-password" placeholder="Digite sua senha" maxlength="255">
                        <button type="button" class="password-toggle" id="togglePassword" aria-label="Mostrar senha" aria-pressed="false">
                            <svg class="eye" viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18M10.6 6.1A9.6 9.6 0 0 1 12 6c6.4 0 10 6 10 6a17 17 0 0 1-3.2 3.9M6.6 6.6A17 17 0 0 0 2 12s3.6 7 10 7a9.7 9.7 0 0 0 4.2-.9M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                        </button>
                    </div>
                    <div class="caps" id="capsHint" role="status"><svg viewBox="0 0 24 24"><path d="M12 4l8 9h-5v5H9v-5H4z"/></svg>Caps Lock está ativado</div>
                </div>

                <button type="submit" class="submit" id="submitButton"><span class="label">Entrar na plataforma</span><span class="spinner" aria-hidden="true"></span><svg class="arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
            </form>

            <div class="help"><strong>Esqueceu a senha?</strong> Peça ao administrador da sua empresa para redefinir o seu acesso.</div>
            <div class="trust">
                <span><svg viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg>Conexão protegida</span>
                <span><svg viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg>Acesso por empresa e perfil</span>
            </div>
            <div class="copyright">© <?= date('Y') ?> YSTEK · Plataforma de gestão para lava-jatos</div>
        </div>
    </section>
</main>
<script>
(function(){
    'use strict';
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ------------------------- formulário ------------------------- */
    var email = document.getElementById('login_email');
    var password = document.getElementById('login_password');
    var form = document.getElementById('loginForm');
    var error = document.getElementById('emailError');
    var emailWrap = document.getElementById('emailWrap');
    var toggle = document.getElementById('togglePassword');
    var submit = document.getElementById('submitButton');
    var caps = document.getElementById('capsHint');
    var card = document.getElementById('card');
    var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    function checkEmail(final) {
        var v = (email.value || '').trim();
        var ok = re.test(v);
        emailWrap.classList.toggle('valid', ok);
        if (ok) { emailWrap.classList.remove('invalid'); error.style.display = 'none'; }
        else if (final && v !== '') { emailWrap.classList.add('invalid'); error.style.display = 'block'; }
        return ok;
    }
    email.addEventListener('input', function () { emailWrap.classList.remove('invalid'); error.style.display = 'none'; checkEmail(false); });
    email.addEventListener('blur', function () { checkEmail(true); });
    toggle.addEventListener('click', function () {
        var showing = password.type === 'text';
        password.type = showing ? 'password' : 'text';
        toggle.classList.toggle('shown', !showing);
        toggle.setAttribute('aria-pressed', String(!showing));
        toggle.setAttribute('aria-label', showing ? 'Mostrar senha' : 'Ocultar senha');
        password.focus();
    });
    function capsState(ev) { if (ev.getModifierState) caps.classList.toggle('on', ev.getModifierState('CapsLock')); }
    password.addEventListener('keydown', capsState); password.addEventListener('keyup', capsState);
    password.addEventListener('blur', function () { caps.classList.remove('on'); });
    form.addEventListener('submit', function (ev) {
        var v = (email.value || '').trim();
        if (!re.test(v)) {
            ev.preventDefault(); emailWrap.classList.add('invalid'); error.style.display = 'block'; email.focus();
            card.classList.remove('shake'); void card.offsetWidth; card.classList.add('shake'); return;
        }
        email.value = v.toLowerCase();
        if (submit.classList.contains('loading')) { ev.preventDefault(); return; }
        submit.classList.add('loading'); submit.setAttribute('aria-busy', 'true');
        submit.querySelector('.label').textContent = 'Entrando...';
    });
    window.addEventListener('pageshow', function () {            // voltar do histórico não deixa o botão travado
        submit.classList.remove('loading'); submit.removeAttribute('aria-busy');
        submit.querySelector('.label').textContent = 'Entrar na plataforma';
    });
    if (document.querySelector('.reason-message.error, .server-message .alert-error')) card.classList.add('shake');
    if (!email.value) email.focus({ preventScroll: true });

    /* ------------------------- bolhas ------------------------- */
    var bubbles = document.getElementById('bubbles');
    if (bubbles && !reduce) {
        for (var i = 0; i < 16; i++) {
            var b = document.createElement('span'), s = 10 + Math.random() * 44;
            b.className = 'bubble';
            b.style.width = b.style.height = s + 'px';
            b.style.left = (Math.random() * 100) + '%';
            b.style.setProperty('--dx', (Math.random() * 60 - 30) + 'px');
            b.style.animationDuration = (11 + Math.random() * 14) + 's';
            b.style.animationDelay = (-Math.random() * 20) + 's';
            bubbles.appendChild(b);
        }
    }

    /* ------------------ brilho que segue o mouse + inclinação ------------------ */
    var hero = document.getElementById('hero'), show = document.getElementById('showcase');
    if (hero && !reduce && window.matchMedia('(pointer:fine)').matches) {
        hero.addEventListener('mousemove', function (e) {
            var r = hero.getBoundingClientRect();
            hero.style.setProperty('--mx', (e.clientX - r.left) + 'px');
            hero.style.setProperty('--my', (e.clientY - r.top) + 'px');
            if (show) {
                var s = show.getBoundingClientRect(), cx = s.left + s.width / 2, cy = s.top + s.height / 2;
                var rx = Math.max(-1, Math.min(1, (e.clientY - cy) / (r.height / 2))) * -4;
                var ry = Math.max(-1, Math.min(1, (e.clientX - cx) / (r.width / 2))) * 5;
                show.style.transform = 'rotateX(' + rx.toFixed(2) + 'deg) rotateY(' + ry.toFixed(2) + 'deg)';
            }
        });
        hero.addEventListener('mouseleave', function () { if (show) show.style.transform = ''; });
    }

    /* ------------------------- vitrine ------------------------- */
    if (!show) return;
    var tabs = [].slice.call(show.querySelectorAll('.sc-tab'));
    var views = [].slice.call(show.querySelectorAll('.view'));
    var prog = document.getElementById('scProgress');
    var caption = document.getElementById('scCaption');
    var capTitle = document.getElementById('capTitle'), capText = document.getElementById('capText');
    var captions = [
        ['Agenda e ordens', 'Organize os horários e acompanhe cada veículo até a finalização.'],
        ['Check-in e checklist', 'Registre quilometragem, combustível, acessórios e avarias na entrada de cada veículo.'],
        ['Gestão financeira', 'Tenha visão do faturamento, pagamentos e resultados da operação.']
    ];
    var DUR = 6500, current = -1, timer = null, paused = false, anims = [];

    function clearAnims() { anims.forEach(clearTimeout); anims = []; }
    function later(fn, ms) { anims.push(setTimeout(fn, ms)); }
    function countUp(el, to, fmt, ms) {
        if (reduce) { el.textContent = fmt(to); return; }
        var t0 = null;
        (function step(t) { if (!t0) t0 = t; var p = Math.min(1, (t - t0) / ms), e = 1 - Math.pow(1 - p, 3); el.textContent = fmt(to * e); if (p < 1) requestAnimationFrame(step); })(performance.now());
    }
    var brl = function (v) { return 'R$ ' + v.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

    var statusFlow = [['st-agendado', 'Agendado'], ['st-aguardando', 'Aguardando'], ['st-servico', 'Em serviço'], ['st-finalizado', 'Finalizado']];
    function runAgenda() {
        var pill = document.getElementById('liveStatus'); if (!pill) return;
        var k = 0; pill.className = 'pill st-agendado'; pill.textContent = 'Agendado';
        (function next() { k++; if (k >= statusFlow.length) return; later(function () { pill.className = 'pill ' + statusFlow[k][0]; pill.textContent = statusFlow[k][1]; next(); }, 1300); })();
    }
    function runCheckin() {
        var items = [].slice.call(document.querySelectorAll('#ckItems .ck-item')); items.forEach(function (i) { i.classList.remove('on'); });
        document.getElementById('fuelBar').style.width = '0';
        countUp(document.getElementById('kmVal'), 45210, function (v) { return Math.round(v).toLocaleString('pt-BR') + ' km'; }, 1200);
        countUp(document.getElementById('fuelVal'), 60, function (v) { return Math.round(v) + '%'; }, 1200);
        later(function () { document.getElementById('fuelBar').style.width = '60%'; }, 80);
        items.forEach(function (it, n) { later(function () { it.classList.add('on'); }, 600 + n * 520); });
    }
    function runFin() {
        countUp(document.getElementById('kpiRev'), 1840, brl, 1400);
        countUp(document.getElementById('kpiOs'), 14, function (v) { return String(Math.round(v)); }, 1200);
        countUp(document.getElementById('kpiTk'), 131.43, function (v) { return 'R$ ' + Math.round(v); }, 1300);
        var bars = [].slice.call(document.querySelectorAll('#bars i'));
        bars.forEach(function (b) { b.style.height = '0'; });
        bars.forEach(function (b, n) { later(function () { b.style.height = b.getAttribute('data-h') + '%'; }, 120 + n * 90); });
    }
    var runners = [runAgenda, runCheckin, runFin];

    function go(i) {
        if (i === current) return; current = i; clearAnims();
        tabs.forEach(function (t, n) { t.setAttribute('aria-selected', String(n === i)); });
        views.forEach(function (v, n) { v.classList.toggle('active', n === i); });
        caption.classList.remove('swap'); void caption.offsetWidth;
        capTitle.textContent = captions[i][0]; capText.textContent = captions[i][1]; caption.classList.add('swap');
        runners[i]();
        restart();
    }
    function restart() {
        clearTimeout(timer); prog.classList.remove('run'); void prog.offsetWidth;
        prog.style.setProperty('--dur', DUR + 'ms');
        if (paused || reduce) return;
        prog.classList.add('run');
        timer = setTimeout(function () { go((current + 1) % tabs.length); }, DUR);
    }
    tabs.forEach(function (t, n) { t.addEventListener('click', function () { current = -1; go(n); }); });
    show.addEventListener('mouseenter', function () { paused = true; clearTimeout(timer); prog.classList.remove('run'); });
    show.addEventListener('mouseleave', function () { paused = false; restart(); });
    document.addEventListener('visibilitychange', function () { if (document.hidden) { clearTimeout(timer); } else { restart(); } });
    go(0);
})();
</script>
</body>
</html>
