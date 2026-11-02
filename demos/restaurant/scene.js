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

  /* Assiette en céramique (tour) */
  const prof = [[0, 0], [0.88, 0], [0.95, 0.02], [1.02, 0.06], [1.22, 0.17], [1.32, 0.215], [1.35, 0.205], [1.25, 0.15], [1.02, 0.085], [0.9, 0.055], [0, 0.055]].map(([x, y]) => new THREE.Vector2(x, y));
  const plate = new THREE.Mesh(new THREE.LatheGeometry(prof, 128), new THREE.MeshPhysicalMaterial({ color: '#f3eee4', roughness: 0.28, clearcoat: 1, clearcoatRoughness: 0.12 }));
  plate.castShadow = true; plate.receiveShadow = true;
  stage.add(plate);

  /* Ingrédients : position finale + point de départ éparpillé */
  const items = [];
  function addItem(obj, delay, spread = 1) {
    obj.traverse((o) => { if (o.isMesh) { o.castShadow = true; o.receiveShadow = true; } });
    const to = { p: obj.position.clone(), q: obj.quaternion.clone(), s: obj.scale.clone() };
    const a = rand() * Math.PI * 2;
    const r = (2.2 + rand() * 1.6) * spread;
    const from = {
      p: new THREE.Vector3(Math.cos(a) * r, 1.6 + rand() * 2.2, Math.sin(a) * r * 0.7),
      q: new THREE.Quaternion().setFromEuler(new THREE.Euler(rand() * 6, rand() * 6, rand() * 6)),
    };
    items.push({ obj, from, to, delay, phase: rand() * 6 });
    stage.add(obj);
  }

  /* Risotto : dôme irrégulier + grains */
  const risotto = new THREE.Group();
  const moundGeo = new THREE.SphereGeometry(0.66, 72, 36, 0, Math.PI * 2, 0, Math.PI / 2);
  const mp = moundGeo.attributes.position;
  for (let i = 0; i < mp.count; i++) {
    const v = new THREE.Vector3().fromBufferAttribute(mp, i);
    const n = 1 + 0.06 * Math.sin(v.x * 9) * Math.cos(v.z * 8) + 0.03 * Math.sin(v.x * 23 + v.z * 17);
    v.multiplyScalar(n);
    mp.setXYZ(i, v.x, v.y * 0.42, v.z);
  }
  moundGeo.computeVertexNormals();
  const cream = new THREE.MeshPhysicalMaterial({ color: '#e9d39c', roughness: 0.4, clearcoat: 0.6, clearcoatRoughness: 0.35, sheen: 0.4, sheenColor: new THREE.Color('#fff2cc') });
  risotto.add(new THREE.Mesh(moundGeo, cream));
  const grains = new THREE.InstancedMesh(new THREE.CapsuleGeometry(0.013, 0.034, 3, 6), new THREE.MeshPhysicalMaterial({ color: '#efdcaa', roughness: 0.35, clearcoat: 0.8 }), 300);
  const m4 = new THREE.Matrix4();
  for (let i = 0; i < 300; i++) {
    const th = rand() * Math.PI * 2;
    const ph = Math.acos(1 - rand() * 0.98);
    const v = new THREE.Vector3(Math.sin(ph) * Math.cos(th) * 0.67, Math.cos(ph) * 0.66 * 0.42 - 0.004, Math.sin(ph) * Math.sin(th) * 0.67);
    m4.compose(v, new THREE.Quaternion().setFromEuler(new THREE.Euler(rand() * 3, rand() * 3, rand() * 3)), new THREE.Vector3(1, 1, 1));
    grains.setMatrixAt(i, m4);
  }
  risotto.add(grains);
  risotto.position.set(0, 0.055, 0);
  addItem(risotto, 0, 0.9);

  /* Tomates cerises confites */
  const tomatoMat = new THREE.MeshPhysicalMaterial({ color: '#c2311d', roughness: 0.18, clearcoat: 1, clearcoatRoughness: 0.05 });
  const stemMat = new THREE.MeshStandardMaterial({ color: '#4f6b2b', roughness: 0.7 });
  [[0.32, 0.22, 0.18], [-0.28, 0.24, 0.22], [0.05, 0.3, -0.3], [-0.36, 0.17, -0.2], [0.42, 0.14, -0.16]].forEach(([x, y, z], i) => {
    const t = new THREE.Group();
    const s = new THREE.Mesh(new THREE.SphereGeometry(0.115, 32, 24), tomatoMat);
    s.scale.set(1, 0.88, 1);
    t.add(s);
    const stem = new THREE.Mesh(new THREE.ConeGeometry(0.05, 0.03, 5), stemMat);
    stem.position.y = 0.1; stem.rotation.y = i;
    t.add(stem);
    t.position.set(x, y, z);
    t.rotation.set(rand() * 0.6, rand() * 6, rand() * 0.6);
    addItem(t, 0.14 + i * 0.05);
  });

  /* Feuilles de sauge croustillantes */
  const leafShape = new THREE.Shape();
  leafShape.moveTo(0, 0);
  leafShape.bezierCurveTo(0.1, 0.07, 0.12, 0.26, 0, 0.42);
  leafShape.bezierCurveTo(-0.12, 0.26, -0.1, 0.07, 0, 0);
  const leafGeo = new THREE.ExtrudeGeometry(leafShape, { depth: 0.006, bevelEnabled: true, bevelThickness: 0.004, bevelSize: 0.004, bevelSegments: 2, curveSegments: 18 });
  const lp = leafGeo.attributes.position;
  for (let i = 0; i < lp.count; i++) lp.setZ(i, lp.getZ(i) + Math.pow(lp.getX(i), 2) * 1.6 - Math.pow(lp.getY(i) - 0.2, 2) * 0.4);
  leafGeo.computeVertexNormals();
  leafGeo.rotateX(-Math.PI / 2 + 0.25);
  const leafMat = new THREE.MeshPhysicalMaterial({ color: '#6f8a4c', roughness: 0.55, sheen: 1, sheenColor: new THREE.Color('#c8d9a8'), side: THREE.DoubleSide });
  for (let i = 0; i < 6; i++) {
    const l = new THREE.Mesh(leafGeo, leafMat);
    const a = (i / 6) * Math.PI * 2 + 0.3;
    l.scale.setScalar(1.35); l.position.set(Math.cos(a) * 0.2, 0.3 - (i % 2) * 0.04, Math.sin(a) * 0.2);
    l.rotation.set(0, -a + Math.PI / 2, 0);
    addItem(l, 0.3 + i * 0.04);
  }

  /* Copeaux de parmesan */
  const parmMat = new THREE.MeshPhysicalMaterial({ color: '#f1d891', roughness: 0.4, transmission: 0.15, thickness: 0.05, side: THREE.DoubleSide });
  for (let i = 0; i < 6; i++) {
    const g = new THREE.PlaneGeometry(0.22, 0.12, 8, 4);
    const pp = g.attributes.position;
    for (let k = 0; k < pp.count; k++) pp.setZ(k, Math.sin(pp.getX(k) * 12) * 0.02);
    g.computeVertexNormals();
    const pm = new THREE.Mesh(g, parmMat);
    const a = rand() * Math.PI * 2;
    pm.position.set(Math.cos(a) * 0.3, 0.28, Math.sin(a) * 0.3);
    pm.rotation.set(-1.1 + rand() * 0.4, rand() * 6, 0.3);
    addItem(pm, 0.48 + i * 0.03);
  }

  /* Points de sauce sur le bord */
  const sauceMat = new THREE.MeshPhysicalMaterial({ color: '#7a3510', roughness: 0.1, clearcoat: 1 });
  for (let i = 0; i < 9; i++) {
    const s = new THREE.Mesh(new THREE.SphereGeometry(0.045 + (i % 3) * 0.012, 20, 12), sauceMat);
    s.scale.y = 0.35;
    const a = -0.6 + i * 0.16;
    s.position.set(Math.cos(a) * 0.93, 0.07, Math.sin(a) * 0.93);
    addItem(s, 0.58 + i * 0.02, 0.6);
  }

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

    /* Assemblage : chaque ingrédient a sa fenêtre de temps */
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
    const dive = sm(0.55, 1, cp);
    stage.rotation.y = -0.35 + cp * 1.4 + mx * 0.25 + time * 0.03;
    stage.position.x = mobile ? 0 : THREE.MathUtils.lerp(1.35, 0.25, sm(0.1, 0.6, cp));
    stage.position.y = mobile ? THREE.MathUtils.lerp(-1.1, -0.6, cp) : 0;
    const camR = THREE.MathUtils.lerp(7.6, 4.6, dive);
    const camY = THREE.MathUtils.lerp(2.2, 4.4, dive) - my * 0.35;
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
