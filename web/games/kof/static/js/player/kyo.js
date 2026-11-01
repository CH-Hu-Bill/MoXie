import { Player } from "./base.js";
import { GIF } from "../utils/gif.js";
export class Kyo extends Player {
    constructor(root, info){
        super(root, info);
        
        this.init_animations();
    }
    init_animations() {
        let outer = this;
        let offsets = [0, -22, -22, -140, 0, 0, 0];
        for (let i = 0; i < 7; i ++) {
            let gif = GIF();
            gif.load(`./static/images/player/kyo/${i}.gif`);
            this.animations.set(i, {
                gif: gif,
                frame_cnt: 0,  // 每张gif图的静态图片数
                frame_rate: 5,  // 每5帧播放一张
                offset_y: offsets[i],  // y方向偏移量
                loaded: false, // 是否加载完成
                scale: 2,  // 放大倍数 
            });

            gif.onload = function() {
                let obj = outer.animations.get(i);
                obj.frame_cnt = gif.frames.length;
                obj.loaded = true;

                if (i === 3) {
                    obj.frame_rate = 4;  // 每4帧播放一张
                }
            }
        }
    }

}