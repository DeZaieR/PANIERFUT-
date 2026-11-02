/* Kerne Bau — scène 3D : la maison se construit pièce par pièce pendant le scroll */
import * as THREE from '../assets/three.module.min.js?v=1';

const DC = window.DC;
const host = document.getElementById('scene');
const hero = document.getElementById('hero');
const labels = Array.from(document.querySelectorAll('#labels .lbl'));
const meter = document.querySelector('.meter__val');

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
  renderer.toneMappingExposure = 1.1;
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  host.appendChild(renderer.domElement);

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(28, 1, 0.1, 200);
  const rand = mulberry(11);

  /* Environnement clair (reflets des vitres et des panneaux) */
  const pmrem = new THREE.PMREMGenerator(renderer);
  const env = new THREE.Scene();
  const sky = new THREE.Mesh(new THREE.SphereGeometry(30, 32, 16), new THREE.MeshBasicMaterial({ color: '#dfe7ee', side: THREE.BackSide }));
  env.add(sky);
  const sun = new THREE.Mesh(new THREE.PlaneGeometry(14, 14), new THREE.MeshBasicMaterial({ color: new THREE.Color(4, 3.8, 3.4) }));
  sun.position.set(10, 16, 8); sun.lookAt(0, 0, 0); env.add(sun);
  const ground = new THREE.Mesh(new THREE.PlaneGeometry(80, 80), new THREE.MeshBasicMaterial({ color: '#b9b2a4' }));
  ground.rotation.x = -Math.PI / 2; ground.position.y = -2; env.add(ground);
  scene.environment = pmrem.fromScene(env, 0.03).texture;

  scene.add(new THREE.HemisphereLight('#ffffff', '#c9c1b2', 0.9));
  const key = new THREE.DirectionalLight('#fff3e2', 2.4);
  key.position.set(7, 12, 6);
  key.castShadow = true;
  key.shadow.mapSize.set(2048, 2048);
  Object.assign(key.shadow.camera, { left: -9, right: 9, top: 9, bottom: -9, near: 1, far: 40 });
  key.shadow.bias = -0.0005;
  key.shadow.normalBias = 0.02;
  key.shadow.radius = 5;
  scene.add(key);

  const world = new THREE.Group();
  scene.add(world);

  /* Sol : reçoit l'ombre, grille de plan */
  const shadowFloor = new THREE.Mesh(new THREE.PlaneGeometry(40, 40), new THREE.ShadowMaterial({ opacity: 0.16 }));
  shadowFloor.rotation.x = -Math.PI / 2;
  shadowFloor.receiveShadow = true;
  world.add(shadowFloor);
  const grid = new THREE.GridHelper(16, 32, '#ff5a1f', '#9fb0c0');
  grid.material.transparent = true; grid.material.opacity = 0.35;
  grid.position.y = 0.002;
  world.add(grid);

  /* Matériaux */
  const M = {
    concrete: new THREE.MeshStandardMaterial({ color: '#cbc4b6', roughness: 0.95 }),
    wall: new THREE.MeshStandardMaterial({ color: '#ff8a50', roughness: 0.85 }),
    roof: new THREE.MeshStandardMaterial({ color: '#2f3339', roughness: 0.6, metalness: 0.2 }),
    frame: new THREE.MeshStandardMaterial({ color: '#2a2d31', roughness: 0.5 }),
    glass: new THREE.MeshPhysicalMaterial({ color: '#9fc3dc', roughness: 0.05, metalness: 0.1, clearcoat: 1, envMapIntensity: 1.6 }),
    wood: new THREE.MeshStandardMaterial({ color: '#9a6a43', roughness: 0.7 }),
    brick: new THREE.MeshStandardMaterial({ color: '#b5583a', roughness: 0.9 }),
    leaf: new THREE.MeshStandardMaterial({ color: '#6f9a5b', roughness: 0.8, flatShading: true }),
    leaf2: new THREE.MeshStandardMaterial({ color: '#86ad6a', roughness: 0.8, flatShading: true }),
    trunk: new THREE.MeshStandardMaterial({ color: '#7a5a3e', roughness: 0.9 }),
  };
  const panelTex = canvasTex(256, (g, w, h) => {
    g.fillStyle = '#1b2a44'; g.fillRect(0, 0, w, h);
    g.strokeStyle = 'rgba(180,200,230,.45)'; g.lineWidth = 2;
    for (let i = 1; i < 6; i++) { g.beginPath(); g.moveTo(i * w / 6, 0); g.lineTo(i * w / 6, h); g.stroke(); }
    for (let i = 1; i < 10; i++) { g.beginPath(); g.moveTo(0, i * h / 10); g.lineTo(w, i * h / 10); g.stroke(); }
    g.strokeStyle = '#c9d2dc'; g.lineWidth = 8; g.strokeRect(0, 0, w, h);
  });
  M.panel = new THREE.MeshPhysicalMaterial({ map: panelTex, roughness: 0.15, metalness: 0.4, clearcoat: 1, envMapIntensity: 1.4 });

  /* Dimensions de la maison */
  const W = 4.4, D = 3.2, H = 2.3, T = 0.18, RH = 1.45, B = 0.25;
  const pieces = [];
  function piece(geo, mat, x, y, z, a0, a1, opts = {}) {
    const m = geo.isObject3D ? geo : new THREE.Mesh(geo, mat);
    m.position.set(x, y, z);
    if (opts.rot) m.rotation.set(...opts.rot);
    m.traverse((o) => { if (o.isMesh) { o.castShadow = true; o.receiveShadow = true; } });
    const to = { p: m.position.clone(), q: m.quaternion.clone() };
    const dir = new THREE.Vector3(x, 0, z).normalize();
    if (!isFinite(dir.x)) dir.set(0, 0, 0);
    const from = {
      p: to.p.clone().add(dir.multiplyScalar(2.5 + rand() * 1.5)).add(new THREE.Vector3((rand() - .5) * 2, 6 + rand() * 3, (rand() - .5) * 2)),
      q: new THREE.Quaternion().setFromEuler(new THREE.Euler((rand() - .5) * 1.4, (rand() - .5) * 2, (rand() - .5) * 1.4)),
    };
    pieces.push({ m, to, from, a0, a1 });
    house.add(m);
    return m;
  }
  const house = new THREE.Group();
  world.add(house);

  piece(new THREE.BoxGeometry(W + 0.7, B, D + 0.7), M.concrete, 0, B / 2, 0, 0, 0.12);
  piece(new THREE.BoxGeometry(W, H, T), M.wall, 0, B + H / 2, -D / 2 + T / 2, 0.12, 0.24);
  piece(new THREE.BoxGeometry(T, H, D - 2 * T), M.wall, -W / 2 + T / 2, B + H / 2, 0, 0.16, 0.28);
  piece(new THREE.BoxGeometry(T, H, D - 2 * T), M.wall, W / 2 - T / 2, B + H / 2, 0, 0.2, 0.32);
  piece(new THREE.BoxGeometry(W, H, T), M.wall, 0, B + H / 2, D / 2 - T / 2, 0.24, 0.36);

  const tri = new THREE.Shape();
  tri.moveTo(-W / 2, 0); tri.lineTo(W / 2, 0); tri.lineTo(0, RH); tri.closePath();
  const gableGeo = new THREE.ExtrudeGeometry(tri, { depth: T, bevelEnabled: false });
  piece(gableGeo, M.wall, 0, B + H, D / 2 - T, 0.36, 0.46);
  piece(gableGeo, M.wall, 0, B + H, -D / 2, 0.38, 0.48);

  const slope = Math.atan2(RH, W / 2);
  const ov = 0.35;
  const ridge = new THREE.Vector2(0, B + H + RH);
  const eave = new THREE.Vector2(W / 2 + ov, B + H - ov * Math.tan(slope));
  const L = ridge.distanceTo(eave) + 0.08;
  const roofGeo = new THREE.BoxGeometry(L, 0.12, D + 0.6);
  const mid = ridge.clone().add(eave).multiplyScalar(0.5);
  const off = 0.07;
  piece(roofGeo, M.roof, mid.x + off * Math.sin(slope), mid.y + off * Math.cos(slope), 0, 0.48, 0.58, { rot: [0, 0, -slope] });
  piece(roofGeo, M.roof, -mid.x - off * Math.sin(slope), mid.y + off * Math.cos(slope), 0, 0.44, 0.54, { rot: [0, 0, slope] });

  const chim = new THREE.Group();
  const ch = new THREE.Mesh(new THREE.BoxGeometry(0.42, 1.2, 0.42), M.brick);
  const cap = new THREE.Mesh(new THREE.BoxGeometry(0.52, 0.08, 0.52), M.frame);
  cap.position.y = 0.62; chim.add(ch, cap);
  piece(chim, null, -1.1, B + H + RH * (1 - 1.1 / (W / 2)) + 0.45, -0.6, 0.54, 0.62);

  function windowMesh(w, h) {
    const g = new THREE.Group();
    const f = new THREE.Mesh(new THREE.BoxGeometry(w + 0.14, h + 0.14, 0.08), M.frame);
    const gl = new THREE.Mesh(new THREE.BoxGeometry(w, h, 0.09), M.glass);
    const bar = new THREE.Mesh(new THREE.BoxGeometry(0.05, h, 0.1), M.frame);
    const sill = new THREE.Mesh(new THREE.BoxGeometry(w + 0.3, 0.06, 0.2), M.concrete);
    sill.position.set(0, -h / 2 - 0.1, 0.06);
    g.add(f, gl, bar, sill);
    return g;
  }
  piece(windowMesh(0.9, 1.0), null, -1.3, B + 1.25, D / 2 + 0.01, 0.6, 0.7);
  piece(windowMesh(0.9, 1.0), null, 1.3, B + 1.25, D / 2 + 0.01, 0.63, 0.73);
  const sideWin = windowMesh(1.2, 0.9); sideWin.rotation.y = Math.PI / 2;
  piece(sideWin, null, W / 2 + 0.01, B + 1.3, 0, 0.66, 0.76, { rot: [0, Math.PI / 2, 0] });
  const atticWin = windowMesh(0.6, 0.45);
  piece(atticWin, null, 0, B + H + 0.55, D / 2 + 0.01, 0.68, 0.78);

  const door = new THREE.Group();
  const leafD = new THREE.Mesh(new THREE.BoxGeometry(0.9, 1.9, 0.08), M.wood);
  const dframe = new THREE.Mesh(new THREE.BoxGeometry(1.04, 2.0, 0.06), M.frame);
  dframe.position.z = -0.02;
  const knob = new THREE.Mesh(new THREE.SphereGeometry(0.04, 12, 8), M.frame);
  knob.position.set(0.3, 0, 0.06);
  door.add(dframe, leafD, knob);
  piece(door, null, 0, B + 0.95, D / 2 + 0.02, 0.7, 0.8);

  /* Panneaux solaires sur le pan droit */
  const nrm = new THREE.Vector3(Math.sin(slope), Math.cos(slope), 0);
  [-0.9, 0.15, 1.2].forEach((z, i) => {
    const pnl = new THREE.Mesh(new THREE.BoxGeometry(1.15, 0.05, 0.95), M.panel);
    const along = 0.95;
    const px = along * Math.cos(slope);
    const py = B + H + RH - along * Math.sin(slope) - 0.02;
    const pos = new THREE.Vector3(px, py, z).add(nrm.clone().multiplyScalar(0.12));
    piece(pnl, null, pos.x, pos.y, pos.z, 0.78 + i * 0.04, 0.88 + i * 0.04, { rot: [0, 0, -slope] });
  });

  /* Arbres stylisés */
  const trees = [];
  [[-3.4, -2.6, 1.1], [-2.4, 3.2, 0.8], [4.4, -2.2, 1.25], [3.6, 3.0, 0.7], [5.4, 0.6, 0.9]].forEach(([x, z, s], i) => {
    const t = new THREE.Group();
    const tr = new THREE.Mesh(new THREE.CylinderGeometry(0.08, 0.12, 0.9, 6), M.trunk);
    tr.position.y = 0.45;
    const f1 = new THREE.Mesh(new THREE.IcosahedronGeometry(0.75, 0), i % 2 ? M.leaf2 : M.leaf);
    f1.position.y = 1.35;
    const f2 = new THREE.Mesh(new THREE.IcosahedronGeometry(0.5, 0), i % 2 ? M.leaf : M.leaf2);
    f2.position.set(0.25, 1.9, 0.1);
    t.add(tr, f1, f2);
    t.traverse((o) => { if (o.isMesh) o.castShadow = true; });
    t.position.set(x, 0, z);
    t.userData.s = s * 0.78;
    t.userData.d = i * 0.03;
    world.add(t);
    trees.push(t);
  });

  /* Scanner « diagnostic » qui balaie la maison à la fin */
  const scanMat = new THREE.MeshBasicMaterial({ color: '#ff5a1f', transparent: true, opacity: 0, side: THREE.DoubleSide, depthWrite: false });
  const scan = new THREE.Mesh(new THREE.PlaneGeometry(W + 1.6, D + 1.6), scanMat);
  scan.rotation.x = -Math.PI / 2;
  const scanEdge = new THREE.LineSegments(new THREE.EdgesGeometry(new THREE.PlaneGeometry(W + 1.6, D + 1.6)), new THREE.LineBasicMaterial({ color: '#ff5a1f', transparent: true, opacity: 0 }));
  scan.add(scanEdge);
  house.add(scan);

  /* Points d'ancrage des étiquettes */
  const anchors = [
    { v: new THREE.Vector3(W / 2 + 0.35, B, D / 2 + 0.35), at: 0.1 },
    { v: new THREE.Vector3(-W / 2, B + H * 0.7, D / 2), at: 0.36 },
    { v: new THREE.Vector3(-0.6, B + H + RH * 0.8, 0), at: 0.56 },
    { v: new THREE.Vector3(1.3, B + 1.6, D / 2), at: 0.74 },
    { v: new THREE.Vector3(1.0, B + H + RH * 0.5, 0.15), at: 0.9 },
  ];

  let mobile = false;
  function resize() {
    const w = host.clientWidth, h = host.clientHeight;
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    mobile = w < 960;
    camera.fov = mobile ? 40 : 28;
    camera.updateProjectionMatrix();
  }
  resize();
  addEventListener('resize', resize);

  let visible = true;
  new IntersectionObserver((e) => { visible = e[0].isIntersecting; }).observe(hero);

  const sm = (a, b, x) => { const t = Math.min(1, Math.max(0, (x - a) / (b - a))); return t * t * (3 - 2 * t); };
  const backOut = (x) => { const c1 = 1.3, c3 = c1 + 1; return 1 + c3 * Math.pow(x - 1, 3) + c1 * Math.pow(x - 1, 2); };
  const wallRaw = new THREE.Color('#ff8a50'), wallDone = new THREE.Color('#f3efe8');
  const v = new THREE.Vector3();
  let cp = 0, mx = 0, my = 0;

  DC.tasks.add((t, dt) => {
    if (!visible || document.hidden) return;
    const k = 1 - Math.pow(1 - 0.08, dt);
    cp += (DC.progressOf(hero) - cp) * k;
    mx += ((DC.fine ? DC.mouse.nx : 0) - mx) * k;
    my += ((DC.fine ? DC.mouse.ny : 0) - my) * k;
    const time = DC.reduce ? 0 : t / 1000;
    const b = 0.13 + cp / 0.95;

    pieces.forEach((pc) => {
      const raw = Math.min(1, Math.max(0, (b - pc.a0) / (pc.a1 - pc.a0)));
      const a = raw <= 0 ? 0 : backOut(raw);
      pc.m.visible = raw > 0;
      pc.m.position.lerpVectors(pc.from.p, pc.to.p, a);
      pc.m.quaternion.slerpQuaternions(pc.from.q, pc.to.q, Math.min(1, a));
    });
    M.wall.color.lerpColors(wallRaw, wallDone, sm(0.42, 0.6, b));

    trees.forEach((tr) => {
      const s = backOut(sm(tr.userData.d, tr.userData.d + 0.12, b + 0.05)) * tr.userData.s;
      tr.scale.setScalar(Math.max(0.001, s));
      tr.rotation.y = Math.sin(time * 0.6 + tr.position.x) * 0.06;
    });

    const sc = sm(0.86, 1, cp);
    scan.position.y = 0.1 + sc * (B + H + RH + 0.3);
    scanMat.opacity = Math.sin(sc * Math.PI) * 0.22;
    scanEdge.material.opacity = Math.sin(sc * Math.PI) * 0.9;

    /* Caméra en orbite */
    const th = -0.55 + cp * 1.3 + mx * 0.25 + time * 0.02;
    const R = (mobile ? 24 : 19.5) - cp * 3;
    const Y = (mobile ? 9.5 : 7.6) - cp * 1.4 - my * 0.8;
    camera.position.set(Math.sin(th) * R, Y, Math.cos(th) * R);
    world.position.x = mobile ? 0 : THREE.MathUtils.lerp(3.1, 0.6, sm(0.55, 0.8, cp));
    world.position.y = mobile ? -2.4 : 0;
    camera.lookAt(world.position.x * 0.62, world.position.y + 1.6, 0);

    renderer.render(scene, camera);

    /* Étiquettes HTML ancrées sur la maison */
    const w = host.clientWidth, h = host.clientHeight;
    anchors.forEach((an, i) => {
      v.copy(an.v); house.localToWorld(v); v.project(camera);
      const el = labels[i];
      el.style.setProperty('--lx', Math.min((v.x * 0.5 + 0.5) * w, w - el.offsetWidth - 8).toFixed(1) + 'px');
      el.style.setProperty('--ly', ((-v.y * 0.5 + 0.5) * h).toFixed(1) + 'px');
      el.classList.toggle('is-on', b > an.at && cp < 0.985);
    });
    if (meter) meter.textContent = Math.round(Math.min(1, cp / 0.97) * 100) + ' %';
  });
}

function mulberry(a) { return () => { a |= 0; a = (a + 0x6d2b79f5) | 0; let t = Math.imul(a ^ (a >>> 15), 1 | a); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; }; }
function canvasTex(size, draw) {
  const c = document.createElement('canvas');
  c.width = c.height = size;
  draw(c.getContext('2d'), size, size);
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.SRGBColorSpace;
  t.anisotropy = 4;
  return t;
}
