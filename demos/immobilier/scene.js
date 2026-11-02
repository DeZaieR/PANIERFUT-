/* Valéa — scène 3D : une tour résidentielle passe du jour au soir, puis s'ouvre étage par étage */
import * as THREE from '../assets/three.module.min.js?v=1';

const DC = window.DC;
const host = document.getElementById('scene');
const hero = document.getElementById('hero');
const flabs = Array.from(document.querySelectorAll('#floors .flab'));

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
  renderer.toneMappingExposure = 1.05;
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  host.appendChild(renderer.domElement);

  const scene = new THREE.Scene();
  scene.fog = new THREE.Fog('#a9c6c4', 40, 110);
  const camera = new THREE.PerspectiveCamera(30, 1, 0.1, 200);
  const rand = mulberry(5);

  /* Environnement : ciel pour les reflets du verre */
  const pmrem = new THREE.PMREMGenerator(renderer);
  const env = new THREE.Scene();
  const eg = new THREE.SphereGeometry(30, 32, 16);
  const ec = [];
  const ep = eg.attributes.position;
  for (let i = 0; i < ep.count; i++) {
    const y = ep.getY(i) / 30;
    const c = new THREE.Color().lerpColors(new THREE.Color('#d9c4a8'), new THREE.Color('#5f9aa3'), Math.max(0, y));
    if (y < 0) c.lerp(new THREE.Color('#1c2a2c'), -y);
    ec.push(c.r, c.g, c.b);
  }
  eg.setAttribute('color', new THREE.Float32BufferAttribute(ec, 3));
  env.add(new THREE.Mesh(eg, new THREE.MeshBasicMaterial({ vertexColors: true, side: THREE.BackSide })));
  const sunBox = new THREE.Mesh(new THREE.PlaneGeometry(12, 6), new THREE.MeshBasicMaterial({ color: new THREE.Color(5, 4, 3) }));
  sunBox.position.set(-14, 8, 10); sunBox.lookAt(0, 0, 0); env.add(sunBox);
  scene.environment = pmrem.fromScene(env, 0.02).texture;

  const hemi = new THREE.HemisphereLight('#dff1f0', '#22312f', 1.1);
  scene.add(hemi);
  const sun = new THREE.DirectionalLight('#fff1dc', 2.6);
  sun.position.set(-10, 14, 8);
  sun.castShadow = true;
  sun.shadow.mapSize.set(2048, 2048);
  Object.assign(sun.shadow.camera, { left: -14, right: 14, top: 18, bottom: -6, near: 1, far: 60 });
  sun.shadow.bias = -0.0005;
  sun.shadow.normalBias = 0.03;
  scene.add(sun);

  const world = new THREE.Group();
  scene.add(world);

  /* Sol et parc */
  const ground = new THREE.Mesh(new THREE.CircleGeometry(90, 64), new THREE.MeshStandardMaterial({ color: '#41544f', roughness: 0.9 }));
  ground.rotation.x = -Math.PI / 2; ground.receiveShadow = true;
  world.add(ground);
  const plaza = new THREE.Mesh(new THREE.CircleGeometry(4.2, 64), new THREE.MeshStandardMaterial({ color: '#c9c6bc', roughness: 0.7 }));
  plaza.rotation.x = -Math.PI / 2; plaza.position.y = 0.01; plaza.receiveShadow = true;
  world.add(plaza);
  const ringPath = new THREE.Mesh(new THREE.RingGeometry(6.5, 7.3, 96), new THREE.MeshStandardMaterial({ color: '#8f8c83', roughness: 0.8 }));
  ringPath.rotation.x = -Math.PI / 2; ringPath.position.y = 0.012; ringPath.receiveShadow = true;
  world.add(ringPath);

  /* Fenêtres (texture générée, allumées le soir) */
  function windowsTex(seed, lit) {
    const r = mulberry(seed);
    return canvasTex(512, 64, (g, w, h) => {
      g.fillStyle = '#000'; g.fillRect(0, 0, w, h);
      const cols = 16;
      for (let i = 0; i < cols; i++) {
        if (r() < lit) {
          const warm = r() < 0.75;
          const grd = g.createLinearGradient(0, 0, 0, h);
          grd.addColorStop(0, warm ? '#ffd38a' : '#bfe8ff');
          grd.addColorStop(1, warm ? '#ff9f4a' : '#7cc7e8');
          g.fillStyle = grd;
          g.globalAlpha = 0.55 + r() * 0.45;
          g.fillRect(i * w / cols + 3, 6, w / cols - 6, h - 12);
          g.globalAlpha = 1;
        }
      }
      g.fillStyle = '#000';
      for (let i = 0; i <= cols; i++) g.fillRect(i * w / cols - 2, 0, 4, h);
    });
  }

  const slabMat = new THREE.MeshStandardMaterial({ color: '#eceee9', roughness: 0.6 });
  const railMat = new THREE.MeshPhysicalMaterial({ color: '#cfe7e6', roughness: 0.05, transparent: true, opacity: 0.35, side: THREE.DoubleSide, depthWrite: false });
  const glassMats = [];
  const floors = [];
  const N = 11, FH = 1.0;
  for (let i = 0; i < N; i++) {
    const f = new THREE.Group();
    const pent = i === N - 1, base = i === 0;
    const size = pent ? 2.3 : 3.0;
    const gh = base ? 1.25 : 0.86;
    const tex = windowsTex(20 + i, base ? 0.95 : 0.55);
    tex.wrapS = THREE.RepeatWrapping;
    const gm = new THREE.MeshPhysicalMaterial({ color: base ? '#24343a' : '#2d4a52', roughness: 0.08, metalness: 0.35, clearcoat: 1, envMapIntensity: 1.3, emissive: '#ffffff', emissiveMap: tex, emissiveIntensity: 0 });
    glassMats.push(gm);
    const slab = new THREE.Mesh(new THREE.BoxGeometry(3.5, 0.14, 3.5), slabMat);
    slab.position.y = 0.07;
    const glass = new THREE.Mesh(new THREE.BoxGeometry(size, gh, size), gm);
    glass.position.set(pent ? -0.4 : 0, 0.14 + gh / 2, pent ? -0.4 : 0);
    f.add(slab, glass);
    if (!base) {
      const r1 = new THREE.Mesh(new THREE.BoxGeometry(3.5, 0.42, 0.03), railMat);
      r1.position.set(0, 0.35, 1.74);
      const r2 = new THREE.Mesh(new THREE.BoxGeometry(0.03, 0.42, 3.5), railMat);
      r2.position.set(1.74, 0.35, 0);
      f.add(r1, r2);
    } else {
      const canopy = new THREE.Mesh(new THREE.BoxGeometry(4.2, 0.08, 1.2), slabMat);
      canopy.position.set(0, 1.3, 1.9);
      f.add(canopy);
    }
    if (pent) {
      const roof = new THREE.Mesh(new THREE.BoxGeometry(2.7, 0.12, 2.7), slabMat);
      roof.position.set(-0.4, 1.06, -0.4);
      const pergola = new THREE.Group();
      for (let k = 0; k < 6; k++) { const b = new THREE.Mesh(new THREE.BoxGeometry(0.06, 0.06, 1.3), slabMat); b.position.set(0.4 + k * 0.22, 1.0, 1.0); pergola.add(b); }
      f.add(roof, pergola);
    }
    f.traverse((o) => { if (o.isMesh && o.material !== railMat) { o.castShadow = true; o.receiveShadow = true; } });
    f.rotation.y = i * 0.075;
    f.userData.baseY = base ? 0 : 0.25 + 1.14 + (i - 1) * FH;
    world.add(f);
    floors.push(f);
  }

  /* Bâtiments voisins */
  const ctx = [];
  [[-9, -6, 2.4, 5.5, 2.4], [-12, 1, 3, 3.2, 2.2], [-6, 10, 2.2, 4.4, 2.8], [-10, 6, 3.2, 2.6, 2.6], [-6, -13, 4, 7, 2.8], [-14, -7, 3, 9, 3]].forEach(([x, z, w, h, d], i) => {
    const tex = windowsTex(70 + i, 0.4);
    tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
    tex.repeat.set(1, Math.round(h * 1.2));
    const m = new THREE.MeshStandardMaterial({ color: '#cfd3cf', roughness: 0.8, emissive: '#ffffff', emissiveMap: tex, emissiveIntensity: 0 });
    glassMats.push(m);
    const b = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), m);
    b.position.set(x, h / 2, z);
    b.castShadow = b.receiveShadow = true;
    world.add(b);
    ctx.push(b);
  });

  /* Arbres du parc */
  const treeMat = new THREE.MeshStandardMaterial({ color: '#4c7458', roughness: 0.9, flatShading: true });
  const trunkMat = new THREE.MeshStandardMaterial({ color: '#5b4636' });
  for (let i = 0; i < 26; i++) {
    const a = (i / 26) * Math.PI * 2 + rand() * 0.2;
    const r = 5.2 + (i % 2) * 3.2 + rand() * 0.6;
    const t = new THREE.Group();
    const tr = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.07, 0.5, 5), trunkMat);
    tr.position.y = 0.25;
    const c = new THREE.Mesh(new THREE.IcosahedronGeometry(0.45 + rand() * 0.25, 0), treeMat);
    c.position.y = 0.85; c.scale.y = 1.25;
    t.add(tr, c);
    t.position.set(Math.cos(a) * r, 0, Math.sin(a) * r);
    t.traverse((o) => { if (o.isMesh) o.castShadow = true; });
    world.add(t);
  }

  /* Lampadaires qui s'allument */
  const lampMat = new THREE.MeshBasicMaterial({ color: '#3a3a3a' });
  const lamps = [];
  for (let i = 0; i < 18; i++) {
    const a = (i / 18) * Math.PI * 2;
    const l = new THREE.Mesh(new THREE.SphereGeometry(0.07, 10, 8), lampMat.clone());
    l.position.set(Math.cos(a) * 6.9, 0.7, Math.sin(a) * 6.9);
    const pole = new THREE.Mesh(new THREE.CylinderGeometry(0.015, 0.015, 0.7, 4), trunkMat);
    pole.position.set(l.position.x, 0.35, l.position.z);
    world.add(l, pole);
    lamps.push(l);
  }
  const glowTex = canvasTex(64, 64, (g, w) => { const gr = g.createRadialGradient(w / 2, w / 2, 0, w / 2, w / 2, w / 2); gr.addColorStop(0, 'rgba(255,200,120,1)'); gr.addColorStop(1, 'rgba(255,200,120,0)'); g.fillStyle = gr; g.fillRect(0, 0, w, w); });
  const glows = lamps.map((l) => { const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, transparent: true, opacity: 0, depthWrite: false, blending: THREE.AdditiveBlending })); s.scale.setScalar(1.2); s.position.copy(l.position); world.add(s); return s; });

  /* Étiquettes des étages */
  const labelFloors = [0, 3, 7, 10];
  const v = new THREE.Vector3();

  let mobile = false;
  function resize() {
    const w = host.clientWidth, h = host.clientHeight;
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    mobile = w < 980;
    camera.fov = mobile ? 44 : 30;
    camera.updateProjectionMatrix();
  }
  resize();
  addEventListener('resize', resize);

  let visible = true;
  new IntersectionObserver((e) => { visible = e[0].isIntersecting; }).observe(hero);

  const sm = (a, b, x) => { const t = Math.min(1, Math.max(0, (x - a) / (b - a))); return t * t * (3 - 2 * t); };
  const fogDay = new THREE.Color('#a9c6c4'), fogNight = new THREE.Color('#0d1d21');
  const sunDay = new THREE.Color('#fff1dc'), sunDusk = new THREE.Color('#ff9a5a');
  let cp = 0, mx = 0, my = 0;

  DC.tasks.add((t, dt) => {
    if (!visible || document.hidden) return;
    const k = 1 - Math.pow(1 - 0.07, dt);
    cp += (DC.progressOf(hero) - cp) * k;
    mx += ((DC.fine ? DC.mouse.nx : 0) - mx) * k;
    my += ((DC.fine ? DC.mouse.ny : 0) - my) * k;
    const time = DC.reduce ? 0 : t / 1000;

    /* Jour → soir */
    const dusk = sm(0.05, 0.42, cp);
    hemi.intensity = THREE.MathUtils.lerp(1.1, 0.22, dusk);
    sun.intensity = THREE.MathUtils.lerp(2.6, 0.35, dusk);
    sun.color.lerpColors(sunDay, sunDusk, dusk);
    sun.position.set(-10, THREE.MathUtils.lerp(14, 3, dusk), 8);
    scene.fog.color.lerpColors(fogDay, fogNight, dusk);
    renderer.toneMappingExposure = THREE.MathUtils.lerp(1.05, 1.25, dusk);
    glassMats.forEach((m, i) => { m.emissiveIntensity = dusk * (i < N ? 1.6 : 1.1) * (0.92 + Math.sin(time * 0.8 + i) * 0.08); });
    lamps.forEach((l, i) => { const on = sm(0.2 + i * 0.01, 0.3 + i * 0.01, cp); l.material.color.setRGB(0.23 + on * 1.2, 0.23 + on * 0.9, 0.23 + on * 0.5); glows[i].material.opacity = on * 0.9; });

    /* Ouverture étage par étage, puis refermeture */
    const open = sm(0.46, 0.66, cp) * (1 - sm(0.9, 1, cp));
    floors.forEach((f, i) => {
      f.position.y = f.userData.baseY + open * i * 0.75;
      f.rotation.y = i * 0.075 + open * Math.sin(i * 0.9) * 0.12;
    });

    /* Caméra en orbite */
    const th = 0.75 + cp * 2.0 + mx * 0.3 + time * 0.015;
    const R = (mobile ? 40 : 31) - sm(0.4, 0.7, cp) * 3 + open * 15;
    const camY = THREE.MathUtils.lerp(3, 10, sm(0, 0.5, cp)) + open * 5 - my * 1.2;
    camera.position.set(Math.sin(th) * R, camY, Math.cos(th) * R);
    const ox = mobile ? 0 : THREE.MathUtils.lerp(6.5, -3.4, sm(0.3, 0.55, cp));
    world.position.set(0, 0, 0);
    const right = new THREE.Vector3(Math.cos(th), 0, -Math.sin(th));
    const lookY = THREE.MathUtils.lerp(5.6, 5.8 + open * 4, sm(0.3, 0.7, cp)) + (mobile ? 5.5 * (1 - sm(0.25, 0.45, cp)) - 1 : 0);
    camera.lookAt(right.x * -ox, lookY, right.z * -ox);

    renderer.render(scene, camera);

    /* Étiquettes HTML accrochées aux étages */
    const w = host.clientWidth, h = host.clientHeight;
    flabs.forEach((el, j) => {
      const f = floors[labelFloors[j]];
      v.set(1.75, 0.55, 0); f.localToWorld(v);
      const sx = v.clone().project(camera);
      el.style.setProperty('--lx', Math.min((sx.x * 0.5 + 0.5) * w + 70, w - el.offsetWidth - 8).toFixed(1) + 'px');
      el.style.setProperty('--ly', ((-sx.y * 0.5 + 0.5) * h - 26).toFixed(1) + 'px');
      el.classList.toggle('is-on', open > 0.6 + j * 0.08);
    });
  });
}

function mulberry(a) { return () => { a |= 0; a = (a + 0x6d2b79f5) | 0; let t = Math.imul(a ^ (a >>> 15), 1 | a); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; }; }
function canvasTex(w, h, draw) {
  const c = document.createElement('canvas');
  c.width = w; c.height = h;
  draw(c.getContext('2d'), w, h);
  const t = new THREE.CanvasTexture(c);
  t.colorSpace = THREE.SRGBColorSpace;
  return t;
}
