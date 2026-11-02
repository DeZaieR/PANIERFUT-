/* Sauge & Ardoise — scène 3D : l'assiette se compose pendant le scroll du héros */
import * as THREE from '../assets/three.module.min.js?v=1';

const DC = window.DC;
const host = document.getElementById('scene');
const hero = document.getElementById('hero');

let renderer = null;
try {
  renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' });
} catch (e) {
  document.documentElement.classList.add('no-webgl');
}
if (renderer) init();

function init() {
  renderer.setPixelRatio(Math.min(devicePixelRatio, 1.75));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.0;
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  host.appendChild(renderer.domElement);

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(30, 1, 0.1, 100);
  const rand = mulberry(7);

  /* Environnement : pièce chaude avec deux « softbox » (reflets du verre et de la céramique) */
  const pmrem = new THREE.PMREMGenerator(renderer);
  const env = new THREE.Scene();
  const envGeo = new THREE.SphereGeometry(20, 32, 16);
  const cols = [];
  const pos = envGeo.attributes.position;
  for (let i = 0; i < pos.count; i++) {
    const y = pos.getY(i) / 20;
    const c = new THREE.Color().lerpColors(new THREE.Color('#1a0f08'), new THREE.Color('#6b4a2c'), (y + 1) / 2);
    cols.push(c.r, c.g, c.b);
  }
  envGeo.setAttribute('color', new THREE.Float32BufferAttribute(cols, 3));
  env.add(new THREE.Mesh(envGeo, new THREE.MeshBasicMaterial({ vertexColors: true, side: THREE.BackSide })));
  const box1 = new THREE.Mesh(new THREE.PlaneGeometry(10, 6), new THREE.MeshBasicMaterial({ color: new THREE.Color(6, 4.6, 3.2) }));
  box1.position.set(-8, 9, 4); box1.lookAt(0, 0, 0); env.add(box1);
  const box2 = new THREE.Mesh(new THREE.PlaneGeometry(4, 10), new THREE.MeshBasicMaterial({ color: new THREE.Color(1.6, 1.8, 2.4) }));
  box2.position.set(10, 4, -6); box2.lookAt(0, 0, 0); env.add(box2);
  scene.environment = pmrem.fromScene(env, 0.04).texture;

  /* Lumières */
  scene.add(new THREE.HemisphereLight('#ffe7c4', '#1a0f08', 0.5));
  const key = new THREE.DirectionalLight('#ffd7a1', 2.6);
  key.position.set(-3, 7, 4);
  key.castShadow = true;
  key.shadow.mapSize.set(1024, 1024);
  Object.assign(key.shadow.camera, { left: -4, right: 4, top: 4, bottom: -4, near: 1, far: 20 });
  key.shadow.bias = -0.0004;
  key.shadow.radius = 6;
  scene.add(key);
  const rim = new THREE.PointLight('#ff9d4d', 30, 12, 2);
  rim.position.set(3, 2.2, -3);
  scene.add(rim);

  /* Tout le décor dans un groupe que l'on fait tourner */
  const stage = new THREE.Group();
  scene.add(stage);

  /* Ardoise (texture générée) */
  const slateTex = canvasTex(512, (g, w, h) => {
    g.fillStyle = '#2b2c2f'; g.fillRect(0, 0, w, h);
    for (let i = 0; i < 1400; i++) {
      g.strokeStyle = `rgba(${rand() > .5 ? 255 : 0},${rand() > .5 ? 255 : 0},255,${rand() * 0.035})`;
      g.lineWidth = rand() * 2;
      const y = rand() * h;
      g.beginPath(); g.moveTo(0, y); g.bezierCurveTo(w * .3, y + (rand() - .5) * 30, w * .7, y + (rand() - .5) * 30, w, y + (rand() - .5) * 20); g.stroke();
    }
  });
  const slateShape = roundedRect(5.4, 3.3, 0.18);
  const slateGeo = new THREE.ExtrudeGeometry(slateShape, { depth: 0.12, bevelEnabled: true, bevelThickness: 0.02, bevelSize: 0.02, bevelSegments: 2 });
  slateGeo.rotateX(-Math.PI / 2);
  slateGeo.translate(0, -0.14, 0);
  const slate = new THREE.Mesh(slateGeo, new THREE.MeshStandardMaterial({ color: '#3a3b40', map: slateTex, roughness: 0.82, metalness: 0.05 }));
  slate.receiveShadow = true;
  slate.rotation.y = -0.22;
  stage.add(slate);

  /* Ombre douce sous l'ardoise */
  const shadowTex = canvasTex(256, (g, w, h) => {
    const gr = g.createRadialGradient(w / 2, h / 2, 0, w / 2, h / 2, w / 2);
    gr.addColorStop(0, 'rgba(0,0,0,.65)'); gr.addColorStop(1, 'rgba(0,0,0,0)');
    g.fillStyle = gr; g.fillRect(0, 0, w, h);
  });
  const fake = new THREE.Mesh(new THREE.PlaneGeometry(9, 6), new THREE.MeshBasicMaterial({ map: shadowTex, transparent: true, depthWrite: false }));
  fake.rotation.x = -Math.PI / 2; fake.position.y = -0.6;
  stage.add(fake);

  /* Assiette : socle en céramique + vraie photo (Pexels) qui se remplit au scroll */
  const items = [];
  const base = new THREE.Mesh(new THREE.CylinderGeometry(1.36, 1.26, 0.07, 128), new THREE.MeshPhysicalMaterial({ color: '#4a4a4e', roughness: 0.35, clearcoat: 1, clearcoatRoughness: 0.2 }));
  base.position.y = 0.035;
  base.castShadow = true; base.receiveShadow = true;
  stage.add(base);

  const loader = new THREE.TextureLoader();
  const tex = (url) => { const t = loader.load(url); t.colorSpace = THREE.SRGBColorSpace; t.anisotropy = 8; return t; };
  const tFull = tex('img/plate-full.webp?v=1');
  const tEmpty = tex('img/plate-empty.webp?v=2');
  const tH = loader.load('img/plate-height.webp?v=2');
  const plateMat = new THREE.ShaderMaterial({
    toneMapped: false,
    uniforms: { tFull: { value: tFull }, tEmpty: { value: tEmpty }, tH: { value: tH }, uFill: { value: 0 }, uTime: { value: 0 } },
    vertexShader: `
      uniform sampler2D tH; uniform float uFill;
      varying vec2 vUv; varying float vM; varying float vEdge;
      float hash(vec2 p){ return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453); }
      float noise(vec2 p){ vec2 i = floor(p), f = fract(p); vec2 u = f * f * (3.0 - 2.0 * f);
        return mix(mix(hash(i), hash(i + vec2(1, 0)), u.x), mix(hash(i + vec2(0, 1)), hash(i + vec2(1, 1)), u.x), u.y); }
      float mask(vec2 uv){
        float r = length(uv - 0.5) * 2.0;
        float n = noise(uv * 7.0) * 0.6 + noise(uv * 19.0) * 0.4;
        float thr = r * 1.35 + n * 0.35;
        return smoothstep(thr - 0.06, thr + 0.02, uFill * 1.75);
      }
      void main(){
        vUv = uv;
        float m = mask(uv);
        vM = m;
        vEdge = m * (1.0 - m) * 4.0;
        float h = texture2D(tH, uv).r;
        vec3 p = position;
        p.y += h * m * 0.12 + h * vEdge * 0.06;
        gl_Position = projectionMatrix * modelViewMatrix * vec4(p, 1.0);
      }`,
    fragmentShader: `
      uniform sampler2D tFull; uniform sampler2D tEmpty; uniform sampler2D tH; uniform float uTime;
      varying vec2 vUv; varying float vM; varying float vEdge;
      void main(){
        float r = length(vUv - 0.5) * 2.0;
        if (r > 0.985) discard;
        vec3 full = texture2D(tFull, vUv).rgb;
        vec3 empty = texture2D(tEmpty, vUv).rgb;
        float e = 1.0 / 256.0;
        float hx = texture2D(tH, vUv + vec2(e, 0.0)).r - texture2D(tH, vUv - vec2(e, 0.0)).r;
        float hy = texture2D(tH, vUv + vec2(0.0, e)).r - texture2D(tH, vUv - vec2(0.0, e)).r;
        vec3 n = normalize(vec3(-hx * 3.0 * vM, 1.0, -hy * 3.0 * vM));
        float light = 0.9 + 0.25 * dot(n, normalize(vec3(-0.4, 1.0, 0.5))) - 0.12;
        vec3 col = mix(empty, full, vM) * light;
        col += vec3(1.0, 0.62, 0.25) * vEdge * 0.35;
        col *= 1.0 - smoothstep(0.9, 0.985, r) * 0.35;
        gl_FragColor = vec4(col, 1.0);
        #include <colorspace_fragment>
      }`,
  });
  const photoGeo = new THREE.PlaneGeometry(2.7, 2.7, 220, 220);
  photoGeo.rotateX(-Math.PI / 2);
  const photo = new THREE.Mesh(photoGeo, plateMat);
  photo.position.y = 0.071;
  photo.rotation.y = 0.4;
  stage.add(photo);

  /* Verre de riesling */
  const glassProf = [[0, 0], [0.34, 0], [0.36, 0.015], [0.34, 0.03], [0.05, 0.05], [0.035, 0.08], [0.03, 0.62], [0.06, 0.68], [0.24, 0.8], [0.33, 0.98], [0.34, 1.18], [0.3, 1.42], [0.29, 1.42], [0.325, 1.18], [0.315, 0.98], [0.23, 0.82], [0.05, 0.71], [0, 0.7]].map(([x, y]) => new THREE.Vector2(x, y));
  const glass = new THREE.Mesh(new THREE.LatheGeometry(glassProf, 96), new THREE.MeshPhysicalMaterial({ color: '#ffffff', roughness: 0.02, metalness: 0, transparent: true, opacity: 0.16, clearcoat: 1, envMapIntensity: 2.4, side: THREE.DoubleSide, depthWrite: false }));
  const wineProf = [[0, 0.71], [0.05, 0.72], [0.22, 0.83], [0.3, 0.98], [0.31, 1.04], [0, 1.04]].map(([x, y]) => new THREE.Vector2(x, y));
  const wine = new THREE.Mesh(new THREE.LatheGeometry(wineProf, 64), new THREE.MeshPhysicalMaterial({ color: '#e4b84e', emissive: '#6b4a10', emissiveIntensity: 0.5, roughness: 0.05, clearcoat: 1, transparent: true, opacity: 0.88 }));
  const glassG = new THREE.Group();
  glassG.add(glass, wine);
  glassG.position.set(1.9, -0.0, -0.95);
  glassG.scale.setScalar(1.15);
  glass.renderOrder = 2; wine.renderOrder = 1;
  stage.add(glassG);

  /* Poussières dorées (vapeur) */
  const N = 260;
  const pg = new THREE.BufferGeometry();
  const pa = new Float32Array(N * 3);
  const seeds = new Float32Array(N);
  for (let i = 0; i < N; i++) { pa[i * 3] = (rand() - .5) * 7; pa[i * 3 + 1] = rand() * 4; pa[i * 3 + 2] = (rand() - .5) * 5; seeds[i] = rand(); }
  pg.setAttribute('position', new THREE.BufferAttribute(pa, 3));
  const dotTex = canvasTex(64, (g, w) => { const gr = g.createRadialGradient(w / 2, w / 2, 0, w / 2, w / 2, w / 2); gr.addColorStop(0, 'rgba(255,220,160,1)'); gr.addColorStop(1, 'rgba(255,220,160,0)'); g.fillStyle = gr; g.fillRect(0, 0, w, w); });
  const dust = new THREE.Points(pg, new THREE.PointsMaterial({ size: 0.06, map: dotTex, transparent: true, opacity: 0.55, depthWrite: false, blending: THREE.AdditiveBlending }));
  scene.add(dust);

  /* Taille et cadrage */
  let mobile = false;
  function resize() {
    const w = host.clientWidth, h = host.clientHeight;
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    mobile = w < 900;
    camera.fov = mobile ? 42 : 30;
    camera.updateProjectionMatrix();
  }
  resize();
  addEventListener('resize', resize);

  /* Rendu seulement quand le héros est visible */
  let visible = true;
  new IntersectionObserver((e) => { visible = e[0].isIntersecting; }).observe(hero);

  const ease = (x) => 1 - Math.pow(1 - x, 3);
  const sm = (a, b, x) => { const t = Math.min(1, Math.max(0, (x - a) / (b - a))); return t * t * (3 - 2 * t); };
  let cp = 0, mx = 0, my = 0;
  const tmpQ = new THREE.Quaternion();
  const look = new THREE.Vector3();

  DC.tasks.add((t, dt) => {
    if (!visible || document.hidden) return;
    const target = DC.progressOf(hero);
    const k = 1 - Math.pow(1 - 0.08, dt);
    cp += (target - cp) * k;
    mx += ((DC.fine ? DC.mouse.nx : 0) - mx) * k;
    my += ((DC.fine ? DC.mouse.ny : 0) - my) * k;
    const time = DC.reduce ? 0 : t / 1000;

    /* Le plat se dépose sur l'assiette (vraie photo) */
    plateMat.uniforms.uFill.value = sm(0.06, 0.8, cp);
    plateMat.uniforms.uTime.value = time;
    const build = cp / 0.72;
    items.forEach((it) => {
      const a = ease(sm(it.delay, it.delay + 0.32, build));
      const o = it.obj;
      o.position.lerpVectors(it.from.p, it.to.p, a);
      o.position.y += Math.sin(a * Math.PI) * 0.7 + (1 - a) * Math.sin(time * 1.3 + it.phase) * 0.12;
      tmpQ.slerpQuaternions(it.from.q, it.to.q, a);
      o.quaternion.copy(tmpQ);
      if (a < 1) o.rotateY((1 - a) * time * 0.6);
    });

    /* Décor qui tourne + caméra qui plonge vers l'assiette */
    const dive = sm(0.5, 0.97, cp);
    stage.rotation.y = -0.35 + cp * 1.4 + mx * 0.25 + time * 0.03;
    stage.position.x = mobile ? 0 : THREE.MathUtils.lerp(1.35, 0.25, sm(0.1, 0.6, cp)) * (1 - dive) + dive * -0.55;
    stage.position.y = mobile ? THREE.MathUtils.lerp(-1.1, -0.6, cp) : 0;
    const camR = THREE.MathUtils.lerp(7.6, mobile ? 3.4 : 2.5, dive);
    const camY = THREE.MathUtils.lerp(2.2, mobile ? 6.2 : 4.9, dive) - my * 0.35;
    camera.position.set(mx * 0.5, camY, camR);
    look.set(stage.position.x * 0.55, stage.position.y + THREE.MathUtils.lerp(0.35, 0.05, dive), 0);
    camera.lookAt(look);

    /* Poussières qui montent */
    const arr = pg.attributes.position.array;
    for (let i = 0; i < N; i++) {
      arr[i * 3 + 1] += 0.004 * dt * (0.4 + seeds[i]);
      arr[i * 3] += Math.sin(time + seeds[i] * 10) * 0.0015;
      if (arr[i * 3 + 1] > 4.2) arr[i * 3 + 1] = -0.2;
    }
    pg.attributes.position.needsUpdate = true;
    rim.intensity = 26 + Math.sin(time * 2.1) * 3 + Math.sin(time * 5.3) * 1.5;

    renderer.render(scene, camera);
  });
}

/* Outils */
function mulberry(a) { return () => { a |= 0; a = (a + 0x6d2b79f5) | 0; let t = Math.imul(a ^ (a >>> 15), 1 | a); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; }; }
function canvasTex(size, draw) {
  const c = document.createElement('canvas');
  c.width = c.height = size;
  draw(c.getContext('2d'), size, size);
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.SRGBColorSpace;
  return t;
}
function roundedRect(w, h, r) {
  const s = new THREE.Shape();
  const x = -w / 2, y = -h / 2;
  s.moveTo(x + r, y);
  s.lineTo(x + w - r, y); s.quadraticCurveTo(x + w, y, x + w, y + r);
  s.lineTo(x + w, y + h - r); s.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
  s.lineTo(x + r, y + h); s.quadraticCurveTo(x, y + h, x, y + h - r);
  s.lineTo(x, y + r); s.quadraticCurveTo(x, y, x + r, y);
  return s;
}
