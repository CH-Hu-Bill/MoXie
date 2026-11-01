import { AcGameObject } from "../ac_game_object/base.js";

export class Player extends AcGameObject {
    constructor(root, info) {
        super();

        this.root = root;
        
        this.id = info.id;
        this.x = info.x;
        this.y = info.y;
        this.width = info.width;
        this.height = info.height;
        this.color = info.color;

        this.direction = 1;

        this.vx = 0;
        this.vy = 0;

        this.speedx = 400;  // 水平速度
        this.speedy = -1000;  // 跳起的初始速度

        this.gravity = 50;

        this.ctx = this.root.game_map.ctx;
        this.pressed_keys = this.root.game_map.controller.pressed_keys;

        this.status = 3;  // 0: idle, 1: 向前, 2: 向后, 3: 跳跃, 4: 攻击, 5: 被打, 6: 死亡
        this.animations = new Map();
        this.frame_current_cnt = 0;

        this.hp = 100;
        this.$hp = this.root.$kof.find(`.kof-head-hp-${this.id}>div`);  // 红色
        this.$hp_div = this.root.$kof.find(`.kof-head-hp-${this.id}>div>div`);  // 绿色
    }

    start() {

    }

    update_control() {
        let w, a, d, space;
        if (this.id === 0) {  // 玩家1
            w = this.pressed_keys.has('w');
            a = this.pressed_keys.has('a');
            d = this.pressed_keys.has('d');
            space = this.pressed_keys.has(' ');
        } else {  // 玩家2
            w = this.pressed_keys.has('ArrowUp');
            a = this.pressed_keys.has('ArrowLeft');
            d = this.pressed_keys.has('ArrowRight');
            space = this.pressed_keys.has('Enter');
        }

        if (this.status === 0 || this.status === 1) {
            if (space) {
                this.status = 4;
                this.vx = 0;
                this.frame_current_cnt = 0;  // 从第0帧开始渲染
            } else if (w) {
                if (d) {
                    this.vx = this.speedx;
                } else if (a) {
                    this.vx = -this.speedx;
                } else {
                    this.vx = 0;
                }
                this.vy = this.speedy;
                this.status = 3;
                this.frame_current_cnt = 0;
            }else if (d) {
                this.vx = this.speedx;
                this.status = 1;
            } else if (a) {
                this.vx = -this.speedx;
                this.status = 1
            } else {
                this.vx = 0;
                this.status = 0;
            }
        }
    }

    update_move() {
        this.vy += this.gravity;
        
        this.x += this.vx * this.timedelta / 1000;
        this.y += this.vy * this.timedelta / 1000; 
        if (this.y > 450) {
            this.y = 450;
            this.vy = 0;
            if(this.status === 3) this.status = 0;
        }

        if (this.x < 0) {
            this.x = 0;
        } else if (this.x + this.width > this.root.game_map.ctx.canvas.width) {
            this.x = this.root.game_map.ctx.canvas.width - this.width;
        }
        if (this.y < 0) {
            this.y = 0;
        }

    }

    update_direction() {
        if(this.status === 6) return;
        let players = this.root.players;
        if(players[0] && players[1]) {
            let me = this, you = players[1 - this.id];
            if (me.x < you.x) me.direction = 1;
            else me.direction = -1;
        }
    }

    is_collision(r1, r2) {  // 碰撞检测
        if (Math.max(r1.x1, r2.x1) > Math.min(r1.x2, r2.x2)) {  // 水平方向没有交集
            return false;
        }
        if (Math.max(r1.y1, r2.y1) > Math.min(r1.y2, r2.y2)) {
            return false;
        }
        return true;
    }

    is_attack() {
        if(this.status === 6) return;
        this.status = 5;
        this.frame_current_cnt = 0;

        this.hp = Math.max(this.hp - 20, 0);

        this.$hp_div.animate({
            width: this.$hp.parent().width() * this.hp / 100
        }, 300);

        this.$hp.animate({
            width: this.$hp.parent().width() * this.hp / 100
        }, 600);
        // this.$hp.width(this.$hp.parent().width() * this.hp / 100);

        if (this.hp <= 0) {
            this.status = 6;
            this.frame_current_cnt = 0;
            this.vx = 0;
        }
    }
    update_attack() {
        if (this.status === 4 && this.frame_current_cnt === 18) {  // 第16~20帧挥拳，判断有无交集
            // this.status = 0;
            let me = this, you = this.root.players[1 - this.id];
            let r1;  // 手臂
            if (this.direction > 0) {
                r1 = {  // 坐标，用render()测试出来的数据
                    x1: me.x + 120,
                    y1: me.y + 40,
                    x2: me.x + 120 + 100,
                    y2: me.y + 40 + 20,
                }
            } else {
                r1 = {  // 坐标，用render()测试出来的数据
                    x1: me.x - 120 + me.width - 100,
                    y1: me.y + 40,
                    x2: me.x - 120 + me.width - 100 + 100,
                    y2: me.y + 40 + 20,
                }
            }

            let r2 = {  // 人物
                x1: you.x,
                y1: you.y,
                x2: you.x + you.width,
                y2: you.y + you.height,
            }

            if (this.is_collision(r1, r2)) {
                you.is_attack();
            }
        }
    }

    update() {
        this.update_control();
        this.update_move();
        this.update_direction();
        this.update_attack();
        this.render();
    }

    render() {
        // this.ctx.fillStyle = this.color;
        // this.ctx.fillRect(this.x, this.y, this.width, this.height);

        // if (this.direction > 0) {
        //     this.ctx.fillStyle = 'green';
        //     // this.ctx.fillRect(this.x + 200, this.y + 40, 20, 20);
        //     this.ctx.fillRect(this.x + 120, this.y + 40, 100, 20);
        // } else {
        //     this.ctx.fillStyle = 'green';
        //     // this.ctx.fillRect(this.x - 200 + this.width - 20, this.y + 40, 20, 20);
        //     this.ctx.fillRect(this.x - 120 + this.width - 100, this.y + 40, 100, 20);
        // } 


        let status = this.status;

        if (this.status === 1 && this.direction * this.vx < 0) {
            status = 2;
        }

        let obj = this.animations.get(status);
        if (obj && obj.loaded) {
            if (this.direction > 0) {
                let k = parseInt(this.frame_current_cnt / obj.frame_rate) % obj.frame_cnt;
                let image = obj.gif.frames[k].image;
                // 由于不同的动画高度不一样，人物移动时会向下走（y轴正方向，这里的坐标系y轴和传统方向相反），因此增加偏移量
                this.ctx.drawImage(image, this.x, this.y + obj.offset_y, image.width * obj.scale, image.height * obj.scale);
            } else {  // 翻转
                this.ctx.save();
                this.ctx.scale(-1, 1); // x方向乘-1，y不变，相当于整个坐标系关于y轴对称
                this.ctx.translate(-this.root.game_map.ctx.canvas.width, 0); // 因为对称过了，所以是负方向平移坐标系

                let k = parseInt(this.frame_current_cnt / obj.frame_rate) % obj.frame_cnt;
                let image = obj.gif.frames[k].image;
                // 由于不同的动画高度不一样，人物移动时会向下走（y轴正方向，这里的坐标系y轴和传统方向相反），因此增加偏移量
                this.ctx.drawImage(image, this.root.game_map.ctx.canvas.width - this.x - this.width, this.y + obj.offset_y, image.width * obj.scale, image.height * obj.scale);

                this.ctx.restore();
            }

        }

        // 攻击和被攻击动画结束后应该变成idle状态，死亡倒地之后应该一直倒地
        if (status === 4 || status === 5 || status === 6) {
            // obj.frame_cnt - 1：防止攻击结束后又播放攻击动作的第一张图 
            if (this.frame_current_cnt === (obj.frame_cnt - 1) * obj.frame_rate){
                if(status === 6) {
                    this.frame_current_cnt --;
                } else{
                    this.status = 0;
                }
                
            }
        }

        this.frame_current_cnt ++;
    }
}

