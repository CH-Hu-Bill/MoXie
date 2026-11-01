import App from '../src/app.js';


window.json = async fileName => await (await fetch(`./data/${fileName}.txt`)).json();

window.hideBanners = (e) => {
    document
        .querySelectorAll(".banner.visible")
        .forEach((b) => b.classList.remove("visible"));
};

const app = new App();
app.initial();