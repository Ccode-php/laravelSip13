# Avtomatik ovozli qo‘ng‘iroq tizimi — o‘rnatish qo‘llanmasi

Vue (veb) → Laravel API + MySQL → Asterisk (AMI orqali) → SIP trunk → provayder.

<<<<<<< HEAD
## hesh nina
=======
Bu qo‘llanma **yangi Ubuntu 24.04 server**ga noldan o‘rnatish uchun. Bo‘limlarni **ketma-ket** bajaring, har bo‘lim oxirida tekshirish bor. Tartib:
>>>>>>> f72307f (deploy: o'rnatish qo'llanmasi va k8s yamllari va boshqa ozgarishlar)

1. Ubuntu tayyorlash
2. Asterisk o‘rnatish va sozlash (serverning o‘zida, podda emas)
3. k3s o‘rnatish
4. Kubernetes yamllarini yaratish
5. Podlarni ishga tushirish (MySQL, Laravel, listener, Reverb, Vue)
6. Birinchi sozlash va tekshirish
7. Xavfsizlik devori (ufw) va portlar
8. NAT / provayder bilan ulanish
9. Yangi versiyani chiqarish
10. Muammolar

## Umumiy sxema

```
Brauzer ──80──► Traefik (k3s ingress)
                 ├─ /      → vue-service      (nginx, statik Vue)
                 ├─ /api   → laravel-service  (:8000)
                 └─ /app   → reverb-service   (:8080, WebSocket)

Laravel pod ──AMI :5038──► Asterisk (server hosti)
Asterisk ──SIP :5060 + RTP 10000-20000/udp──► Provayder
```

| Nima | Qayerda ishlaydi |
|---|---|
| Asterisk | Ubuntu hostida (systemd), podda emas |
| MySQL, Laravel, listener, Reverb, Vue | k3s podlarida, namespace `avtosip` |
| SIP trunk fayli | Laravel yozadi → `/etc/asterisk/laravel/pjsip_trunks.conf` |
| Audio fayllar | Laravel yozadi → `/var/lib/asterisk/sounds/campaign/` |

Podlar hostning ikki papkasini `hostPath` orqali ko‘radi. **Papkalar podlardan oldin mavjud bo‘lishi shart**, aks holda pod ishga tushmaydi.

---

## 1. Ubuntu tayyorlash

```bash
sudo apt update && sudo apt -y upgrade
sudo timedatectl set-timezone Asia/Tashkent
sudo apt -y install curl git openssl
```

Serverga **o‘zgarmas (statik) IP** bering (router DHCP rezervi yoki netplan). Laravel Asterisk'ni shu IP orqali topadi, IP o‘zgarsa hammasi uziladi.

Server IP sini o‘zgaruvchiga olib qo‘ying (keyingi qadamlarda ishlatiladi):

```bash
SERVER_IP=$(hostname -I | awk '{print $1}'); echo $SERVER_IP
```

---

## 2. Asterisk

### 2.1 O‘rnatish

```bash
sudo apt -y install asterisk
asterisk -V            # Ubuntu 24.04 da 20.6.x bo‘lishi kerak
sudo systemctl enable --now asterisk
```

### 2.2 Papkalar (podlar shularni ulaydi)

```bash
sudo mkdir -p /etc/asterisk/laravel /var/lib/asterisk/sounds/campaign
sudo chown asterisk:asterisk /etc/asterisk/laravel /var/lib/asterisk/sounds/campaign
sudo chmod 755 /etc/asterisk/laravel /var/lib/asterisk/sounds/campaign
```

Laravel (pod ichida root sifatida) bu papkalarga fayl yozadi, Asterisk esa ularni o‘qiydi. Fayllar `644` bo‘ladi, shuning uchun `777` kerak emas.

### 2.3 `manager.conf` (AMI — Laravel shu orqali qo‘ng‘iroq boshlaydi)

Parolni avtomatik yaratamiz va ekranga chiqaramiz:

```bash
AMI_PASS=$(openssl rand -hex 12); echo "AMI parol: $AMI_PASS"
sudo cp /etc/asterisk/manager.conf /etc/asterisk/manager.conf.orig

sudo tee /etc/asterisk/manager.conf >/dev/null <<EOF
[general]
enabled = yes
webenabled = no
port = 5038
bindaddr = 0.0.0.0

[laravel]
secret = $AMI_PASS
deny = 0.0.0.0/0.0.0.0
permit = 127.0.0.1/255.255.255.255
permit = 10.42.0.0/255.255.0.0
read = system,call,log,verbose,command,agent,user,config,dtmf,reporting
write = system,originate,config

#include "manager.d/*.conf"
EOF
```

- `permit = 10.42.0.0/255.255.0.0` — k3s podlari tarmog‘i. Laravel podi Asterisk'ka shu manzildan ulanadi.
- Kompyuteringizdan (dasturlash paytida) ulanmoqchi bo‘lsangiz, o‘sha kompyuter IP sini alohida `permit = IP/255.255.255.255` qatori bilan qo‘shing. Productionda kerak emas.
- `bindaddr = 0.0.0.0` qoldirildi, internetdan himoya ufw bilan qilinadi (7-bo‘lim).

### 2.4 `pjsip.conf` (transport + trunklar fayliga havola)

```bash
sudo cp /etc/asterisk/pjsip.conf /etc/asterisk/pjsip.conf.orig

sudo tee /etc/asterisk/pjsip.conf >/dev/null <<'EOF'
[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0:5060

; Laravel yaratadigan trunklar shu faylda
#tryinclude laravel/pjsip_trunks.conf
EOF
```

Asterisk **internetga NAT orqasidan** chiqsa, transportga qo‘shimcha qatorlar kerak, 8-bo‘limga qarang.

### 2.5 `rtp.conf` (ovoz portlari)

```bash
sudo tee /etc/asterisk/rtp.conf >/dev/null <<'EOF'
[general]
rtpstart=10000
rtpend=20000
EOF
```

Shu diapazon ufw da ochiladi (7-bo‘lim). **Yopiq bo‘lsa, qo‘ng‘iroq o‘tadi, lekin ovoz eshitilmaydi.**

### 2.6 `extensions_campaign.conf` (qo‘ng‘iroq ko‘tarilganda audio ijrosi)

```bash
sudo tee /etc/asterisk/extensions_campaign.conf >/dev/null <<'EOF'
[campaign-playback]
; Laravel Originate bilan shu kontekstga yuboradi.
; O‘zgaruvchilar: RECIPIENT_ID, ATTEMPT, AUDIO_FILE (masalan campaign/xxxx-uuid)
exten => s,1,NoOp(campaign call recipient=${RECIPIENT_ID} attempt=${ATTEMPT})
 same => n,Set(PLAYED=0)
 same => n,Answer()
 same => n,Wait(1)
 same => n,Playback(/var/lib/asterisk/sounds/${AUDIO_FILE})
 same => n,GotoIf($["${PLAYBACKSTATUS}" != "SUCCESS"]?end)
 same => n,Set(PLAYED=1)
 same => n(end),Wait(1)
 same => n,Hangup()

; Qo‘ng‘iroq tugaganda Laravel ga xabar beradi (PLAYED=1: audio oxirigacha eshitilgan)
exten => h,1,UserEvent(CampaignCallEnd,RecipientID: ${RECIPIENT_ID},Attempt: ${ATTEMPT},Played: ${PLAYED})

[campaign-reject]
; Provayderdan kelgan kiruvchi qo‘ng‘iroqlar rad etiladi (toll fraud himoyasi)
exten => _.,1,Hangup(21)
EOF
```

`Playback` yo‘li **to‘liq** (`/var/lib/asterisk/...`) bo‘lishi shart. Ubuntu'da nisbiy yo‘l topilmaydi va qo‘ng‘iroq 1 soniyada uziladi.

`extensions.conf` ga ulash (bir marta):

```bash
grep -q 'extensions_campaign.conf' /etc/asterisk/extensions.conf || \
  echo '#include extensions_campaign.conf' | sudo tee -a /etc/asterisk/extensions.conf
```

### 2.7 Qayta yuklash va tekshirish

```bash
sudo systemctl restart asterisk
sudo asterisk -rx "manager show users"            # laravel ko‘rinishi kerak
sudo asterisk -rx "dialplan show campaign-playback"
sudo asterisk -rx "pjsip show transports"         # transport-udp
sudo ss -lntup | grep -E '5038|5060'
```

---

## 3. k3s

```bash
curl -sfL https://get.k3s.io | sh -
sudo systemctl enable k3s       # odatda avtomatik
```

`kubectl` ni oddiy foydalanuvchi sifatida ishlatish:

```bash
mkdir -p ~/.kube
sudo cp /etc/rancher/k3s/k3s.yaml ~/.kube/config
sudo chown $USER:$USER ~/.kube/config
echo 'export KUBECONFIG=$HOME/.kube/config' >> ~/.bashrc
export KUBECONFIG=$HOME/.kube/config

kubectl get nodes               # STATUS = Ready (1 daqiqagacha kuting)
kubectl -n kube-system get pods # traefik Running bo‘lishi kerak
```

---

## 4. Kubernetes yamllarini yaratish

Hamma yaml fayllar shu repozitoriyadagi [`deploy/k8s/`](deploy/k8s) papkasida turadi. Ularni serverga **qo‘lda** nusxalaymiz (butun kodni yuklab olish shart emas, dastur tayyor rasm ko‘rinishida GitHub'dan tortiladi).

### 4.1 Papkalarni yaratish

```bash
mkdir -p ~/k3s/siptrunk/{laravel,mysql,vue}
cd ~/k3s/siptrunk
```

Oxirida shunday tuzilma bo‘lishi kerak:

```
~/k3s/siptrunk/
├── ingress.yaml
├── laravel/
│   ├── laravel-deployment.yaml     (ConfigMap + laravel + listener + service)
│   └── reverb-deployment.yaml      (Reverb WebSocket)
├── mysql/
│   └── mysql-deployment.yaml       (PVC + MySQL + service)
└── vue/
    └── vue-deployment.yaml         (Vue + service)
```

### 4.2 Fayllarni nusxalash (5 ta)

Har bir fayl uchun:

1. GitHub'da `deploy/k8s/...` ichidagi faylni oching va **Raw** tugmasini bosing, hamma matnni nusxalang.
2. Serverda faylni oching: `nano ingress.yaml`
3. Nusxalangan matnni joylang (SSH oynasida o‘ng tugma yoki `Shift+Insert`).
4. Saqlang: `Ctrl+O`, `Enter`, so‘ng `Ctrl+X`.

```bash
nano ingress.yaml
nano laravel/laravel-deployment.yaml
nano laravel/reverb-deployment.yaml
nano mysql/mysql-deployment.yaml
nano vue/vue-deployment.yaml
```

YAML da **bo‘sh joylar (probel) muhim**, tab ishlamaydi. Nusxalashda qatorlar siljib ketmaganini tekshirish uchun har fayldan keyin:

```bash
kubectl apply --dry-run=client -f ingress.yaml
```

(boshqa fayllar uchun ham xuddi shunday). Xato bo‘lmasa, `... created (dry run)` chiqadi. Hech narsa o‘rnatilmaydi, faqat tekshiriladi.

### 4.3 `ASTERISK_AMI_HOST` ga shu serverning IP sini yozish

```bash
echo $SERVER_IP                         # 1-bo‘limda olgan IP
nano laravel/laravel-deployment.yaml
```

Faylda `ASTERISK_AMI_HOST` qatorini toping (`Ctrl+W`, so‘ng yozing, `Enter`) va qo‘shtirnoq ichiga **shu serverning IP sini** yozing:

```yaml
  ASTERISK_AMI_HOST: "192.168.1.155"     # <- o‘zingizning IP
```

Xohlasangiz `APP_URL` ni ham `http://SERVER_IP` ga o‘zgartiring. Tekshirish: `grep ASTERISK_AMI_HOST laravel/laravel-deployment.yaml`

### 4.4 Rasm (image) teglarini qo‘lda yozish

Rasm tegi har safar yangi yig‘ilganda o‘zgaradi (`v1.0.N`). Eng so‘nggi tegni GitHub → **Packages** (yoki **Actions**) dan oling va `image:` qatorlariga qo‘lda yozing. Jami 4 joy:

| Fayl | Nechta joy | Rasm |
|---|---|---|
| `laravel/laravel-deployment.yaml` | 2 ta (`laravel` va `listener`) | `ghcr.io/ccode-php/laravelsip13:v1.0.N` |
| `laravel/reverb-deployment.yaml` | 1 ta | `ghcr.io/ccode-php/laravelsip13:v1.0.N` |
| `vue/vue-deployment.yaml` | 1 ta | `ghcr.io/ccode-php/vuesip:v1.0.N` |

Laravel rasmi uchta joyda **bir xil teg** bo‘lishi shart. Vue repozitoriyasining teg raqami boshqa bo‘ladi, o‘z raqamini yozing. Tekshirish:

```bash
grep -rn "image:" laravel vue
```

**Rasmlar private bo‘lsa**, k3s ularni tortolmaydi (`ErrImagePull`). Ikki yo‘l:

- GitHub → Packages → rasm → Package settings → *Change visibility → Public* (eng oson), yoki
- Pull secret yarating (PAT: faqat `read:packages`) va har Deployment'ning `spec.template.spec` qismiga `imagePullSecrets: [{name: ghcr}]` qo‘shing:

  ```bash
  kubectl -n avtosip create secret docker-registry ghcr \
    --docker-server=ghcr.io --docker-username=GITHUB_LOGIN --docker-password=PAT
  ```

---

## 5. Podlarni ishga tushirish

### 5.1 Namespace va Secret

Secret larni GitHub'ga **yozmang**, klasterda qo‘lda yaratamiz. AMI paroli `manager.conf` dagi bilan **bir xil** bo‘lishi shart, shuning uchun uni o‘sha fayldan olamiz:

```bash
kubectl create namespace avtosip

AMI_PASS=$(sudo awk -F' *= *' '/^secret/{print $2}' /etc/asterisk/manager.conf)
DB_PASS=$(openssl rand -hex 16)
echo "MySQL parol (saqlab qo‘ying): $DB_PASS"

kubectl -n avtosip create secret generic laravel-secret \
  --from-literal=APP_KEY="base64:$(openssl rand -base64 32)" \
  --from-literal=DB_PASSWORD="$DB_PASS" \
  --from-literal=ASTERISK_AMI_SECRET="$AMI_PASS" \
  --from-literal=REVERB_APP_SECRET="$(openssl rand -hex 24)"

kubectl -n avtosip get secret laravel-secret
```

Parollarni o‘zingiz o‘ylab topmaysiz, `openssl rand` o‘zi yaratadi:

- `AMI_PASS` 2.3-bo‘limda yaratilib `manager.conf` ga yoziladi, bu yerda esa o‘sha fayldan o‘qib Secret'ga qo‘yiladi, shuning uchun ikkalasi doim bir xil.
- `DB_PASS` shu yerda yaratiladi va faqat Secret'ga yoziladi. MySQL ham, Laravel ham parolni shu Secret'dan oladi. Keyin ko‘rish kerak bo‘lsa: `kubectl -n avtosip get secret laravel-secret -o jsonpath='{.data.DB_PASSWORD}' | base64 -d; echo`
- **Muhim:** MySQL parolni birinchi ishga tushganda diskka yozib qo‘yadi. Keyin Secret'ni boshqa `DB_PASS` bilan qayta yaratsangiz, Laravel MySQL'ga kira olmaydi. Qayta yaratish kerak bo‘lsa, eski parolni saqlang yoki MySQL ma'lumotlarini (`kubectl -n avtosip delete pvc mysql-pv-claim`) o‘chirib, noldan boshlang.

Secret ichidagi to‘rt kalit: `APP_KEY`, `DB_PASSWORD` (MySQL root paroli ham shu), `ASTERISK_AMI_SECRET`, `REVERB_APP_SECRET`.

> Keyingi `apply` da `namespace/avtosip` haqida *"missing the kubectl.kubernetes.io/last-applied-configuration annotation"* ogohlantirishi chiqsa, bu normal, e'tibor bermang.

### 5.2 Yamllarni qo‘llash (tartib muhim)

```bash
cd ~/k3s/siptrunk

# 1) MySQL (PVC + Deployment + Service)
kubectl apply -f mysql/mysql-deployment.yaml
kubectl -n avtosip rollout status deploy/mysql --timeout=180s

# 2) Laravel: ConfigMap, laravel, listener, laravel-service
kubectl apply -f laravel/laravel-deployment.yaml

# 3) Reverb (WebSocket)
kubectl apply -f laravel/reverb-deployment.yaml

# 4) Vue
kubectl apply -f vue/vue-deployment.yaml

# 5) Ingress
kubectl apply -f ingress.yaml

kubectl -n avtosip get pods
```

Kutiladigan holat, 5 ta pod hammasi `Running`:

```
laravel-...            1/1 Running
laravel-listener-...   1/1 Running
mysql-...              1/1 Running
reverb-...             1/1 Running
vue-frontend-...       1/1 Running
```

MySQL birinchi ishga tushganda 1 daqiqagacha vaqt oladi. `listener` bu paytda bir-ikki marta qayta ishga tushishi mumkin, bu normal.

### 5.3 Bazani yaratish (migratsiya)

```bash
kubectl -n avtosip exec deploy/laravel -- php artisan migrate --force
```

(`migrate:fresh --force` **hamma ma'lumotni o‘chiradi**, faqat bazani noldan boshlash kerak bo‘lganda ishlating.)

---

## 6. Tekshirish

```bash
# 1. Veb ochiladimi
curl -sI http://localhost/ | head -1                 # HTTP/1.1 200 OK

# 2. Laravel AMI ga ulana oladimi (listener logi)
kubectl -n avtosip logs deploy/laravel-listener --tail=20

# 3. Reverb
kubectl -n avtosip logs deploy/reverb --tail=5       # "Starting server on 0.0.0.0:8080"
```

Brauzerda `http://SERVER_IP/` ni oching. Keyin:

1. **SIP Trunk** yarating (provayder ma'lumotlari bilan).
2. Serverda trunk yozilganini tekshiring:
   ```bash
   cat /etc/asterisk/laravel/pjsip_trunks.conf
   sudo asterisk -rx "pjsip show endpoints"
   ```
3. Audio yuklang (fayl `/var/lib/asterisk/sounds/campaign/` ga `.wav` bo‘lib tushadi).
4. Abonent qo‘shing va kampaniya yarating. Avval **o‘zingizning raqamingiz** bilan sinab ko‘ring.

Jonli kuzatish: `sudo asterisk -rvvv`.

---

## 7. ufw va portlar

> Hammasi ishlab bo‘lgandan **keyin** yoqamiz. Shunda nimadir ishlamay qolsa, sababi ufw ekani aniq bo‘ladi.
> **Birinchi bo‘lib 22 (SSH) ni oching**, aks holda serverdan chiqib ketasiz.

| Port | Protokol | Nima uchun | Kimga ochiladi |
|---|---|---|---|
| 22 | TCP | SSH | hamma (yoki faqat sizning IP) |
| 80 | TCP | Veb (Traefik) | hamma / ichki tarmoq |
| 443 | TCP | HTTPS (keyinchalik sertifikat qo‘ysangiz) | hamma |
| 5060 | UDP | SIP (signalizatsiya) | **faqat provayder IP** |
| **10000–20000** | **UDP** | **RTP, ya'ni ovoz** | **faqat provayder IP** (yoki hamma) |
| 5038 | TCP | AMI | **faqat k3s podlari** (pod tarmog‘i qoidasi ichiga kiradi) |
| 6443 | TCP | k3s API | faqat boshqa kompyuterdan `kubectl` kerak bo‘lsa |
| 3306, 8000, 8080 | — | MySQL, Laravel, Reverb | **ochilmaydi**, ular faqat klaster ichida |

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing

sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# k3s ichki trafigi (podlar -> host: AMI :5038 shu qoidaga kiradi)
sudo ufw allow from 10.42.0.0/16        # podlar
sudo ufw allow from 10.43.0.0/16        # servislar

# Provayder bilan (PROVAYDER_IP ni almashtiring)
sudo ufw allow from PROVAYDER_IP to any port 5060 proto udp
sudo ufw allow from PROVAYDER_IP to any port 10000:20000 proto udp

# Provayder IP si noma'lum yoki o‘zgaruvchan bo‘lsa, ovozni hamma uchun oching:
# sudo ufw allow 10000:20000/udp

sudo ufw enable
sudo ufw status verbose
```

> **Sinov:** boshqa kompyuterdan `http://SERVER_IP/` ochilishini tekshiring. Faqat serverning o‘zida ochilib, tashqaridan ochilmasa, ufw podlar yo‘nalishini to‘sgan. Yechim: `sudo ufw default allow routed`.
> Podlar rasm tortolmasa yoki internetga chiqolmasa ham shu qoida yordam beradi.

FreePBX bilan sinovda (ikkala server bir LAN da) provayder IP si o‘rniga FreePBX IP si ishlatiladi.

---

## 8. NAT va provayder

- **Asterisk serveri to‘g‘ridan-to‘g‘ri tashqi IP da yoki provayder bilan bir tarmoqda** (masalan FreePBX, Panasonic) bo‘lsa, qo‘shimcha hech narsa kerak emas.
- **Asterisk router (NAT) orqasida, provayder internetda** bo‘lsa, `pjsip.conf` dagi transportga qo‘shing:

  ```ini
  [transport-udp]
  type=transport
  protocol=udp
  bind=0.0.0.0:5060
  local_net=192.168.1.0/24
  external_media_address=TASHQI_IP
  external_signaling_address=TASHQI_IP
  ```

  va routerda **UDP 5060** hamda **UDP 10000–20000** portlarini Asterisk serveriga yo‘naltiring (port forwarding). Keyin: `sudo systemctl restart asterisk`.
- Trunkni **provayder bergan ma'lumot** bilan to‘ldiring (login/parol yoki IP bo‘yicha). Provayder ro‘yxatdan o‘tishni (REGISTER) talab qilsa, hozirgi tizim buni avtomatik qilmaydi, alohida sozlash kerak.
- Raqam oldiga prefiks (masalan `0`) qo‘shish tizimda hozircha yo‘q.

---

## 9. Yangi versiyani chiqarish

1. Kodni `main` ga push qiling. GitHub Actions yangi rasm yig‘adi (`v1.0.N`, N — yig‘ish raqami).
2. Serverda tegni almashtiring va qo‘llang:

```bash
cd ~/k3s/siptrunk
N=12    # Actions dagi yangi raqam

# Laravel (laravel, listener va reverb bir xil rasm)
sed -i "s#laravelsip13:v1\.0\.[0-9]*#laravelsip13:v1.0.$N#" laravel/*.yaml
kubectl apply -f laravel/

# Vue
sed -i "s#vuesip:v1\.0\.[0-9]*#vuesip:v1.0.$N#" vue/vue-deployment.yaml
kubectl apply -f vue/

kubectl -n avtosip get pods
```

Laravel va Vue repozitoriyalarining yig‘ish raqamlari alohida, shuning uchun `N` ikkalasida har xil bo‘lishi mumkin. Har biri uchun o‘z raqamini yozing.

Teg **har safar yangi bo‘lishi shart**, chunki `imagePullPolicy: IfNotPresent` eski rasmni qayta ishlatadi.

ConfigMap o‘zgarsa podlar uni o‘zi o‘qimaydi:

```bash
kubectl -n avtosip rollout restart deploy/laravel deploy/laravel-listener deploy/reverb
```

Migratsiya o‘zgargan bo‘lsa: `kubectl -n avtosip exec deploy/laravel -- php artisan migrate --force`.

---

## 10. Muammolar

| Belgi | Sabab va yechim |
|---|---|
| `ErrImagePull` / `ImagePullBackOff` | Rasm private yoki teg xato → 4.4 bo‘lim |
| Pod `ContainerCreating` da qotgan, `hostPath ... not exist` | 2.2 dagi papkalar yaratilmagan |
| `CreateContainerConfigError` | `laravel-secret` yo‘q yoki kalit yetishmaydi → `kubectl -n avtosip describe pod ...` |
| `reverb` `CrashLoopBackOff` | `pcntl` rasmda yo‘q (Dockerfile) yoki Reverb env da `DB_PASSWORD`/`REVERB_*` yo‘q |
| Listener logida AMI ulanmayapti / `Authentication failed` | `ASTERISK_AMI_HOST` xato, `manager.conf` paroli Secret bilan bir xil emas, `permit 10.42.0.0` yo‘q yoki ufw to‘sgan |
| Qo‘ng‘iroq bordi, **1 soniyada uzildi** | Playback yo‘li (2.6) yoki audio fayl yo‘q: `ls /var/lib/asterisk/sounds/campaign` |
| Qo‘ng‘iroq bor, **ovoz yo‘q** | RTP yopiq (UDP 10000–20000, ufw va router), NAT sozlamalari (8-bo‘lim) |
| `SIP/2.0 401/403` | Provayder login/parol yoki IP ruxsati noto‘g‘ri |
| Asterisk logida `OPTIONS ... No matching endpoint` | Zararsiz, FreePBX holatni tekshiryapti |
| `pjsip show transports` bo‘sh / 5060 band | `sudo ss -lunp \| grep 5060`. Boshqa jarayon band qilgan bo‘lsa, `/etc/asterisk/modules.conf` ga `noload = chan_sip.so` qo‘shing |
| Veb ochiladi, jonli yangilanish yo‘q | `kubectl -n avtosip logs deploy/reverb`, ingress da `/app` borligini tekshiring |
| `migrate` da `Connection refused` | MySQL hali tayyor emas: `kubectl -n avtosip rollout status deploy/mysql` |

Foydali buyruqlar:

```bash
kubectl -n avtosip get pods
kubectl -n avtosip logs deploy/laravel --tail=50
kubectl -n avtosip logs deploy/laravel-listener -f
kubectl -n avtosip describe pod <pod-nomi>
sudo asterisk -rvvv
sudo ufw status verbose
```