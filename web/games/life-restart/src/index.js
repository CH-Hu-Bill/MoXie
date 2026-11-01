import App from '../src/app.js';
import ageData from '../data/age.txt';
import eventsData from '../data/events.txt';
import talentsData from '../data/talents.txt';

const embeddedData = {
    age: ageData,
    events: eventsData,
    talents: talentsData,
};

window.json = async fileName => {
    if (embeddedData[fileName]) return embeddedData[fileName];
    return await (await fetch(`./data/${fileName}.txt`)).json();
};

window.hideBanners = (e) => {
    document
        .querySelectorAll(".banner.visible")
        .forEach((b) => b.classList.remove("visible"));
};

const app = new App();
app.initial()
    .then(() => { window.__lifeLoaded = true; })
    .catch(error => { console.error(error); });