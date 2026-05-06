# Security Policy

Midori Sync trata la seguridad como prioridad. Si encuentras una
vulnerabilidad, por favor reportala de forma responsable.

## Reporte privado

- **Email**: `security@astian.org`
- **PGP**: ver [`.well-known/security.txt`](public/.well-known/security.txt)
  para la clave publica y huella.
- NO abrir issue publica en GitHub para reportes de seguridad.

Incluir en el reporte:

1. Descripcion del problema y impacto estimado.
2. Pasos para reproducir (preferiblemente con commit/version
   afectada).
3. PoC minima si esta disponible.
4. Tu contacto preferido.

## SLA de respuesta

- Acuse de recibo: <=72 horas habiles.
- Triage inicial y severidad asignada: <=7 dias.
- Fix para vulnerabilidades criticas: <=30 dias.
- Fix para vulnerabilidades altas: <=60 dias.
- Coordinacion publica via CVE / advisory cuando aplique.

## Alcance

Cubre:

- Backend Laravel (`app/`, `routes/`, `config/`).
- Web dashboard (`resources/js/Pages/`).
- Extension (`extension/`).
- Imagenes Docker oficiales y `docker-compose.yml`.
- Documentacion en `docs/` cuando exponga procedimientos inseguros.

Fuera de alcance:

- Authentik mismo (reportar upstream).
- Vulnerabilidades en dependencias ya con CVE publico (preferimos
  Dependabot).
- Issues que requieren acceso fisico al device del usuario o privilegios
  root previos en el host.

## Disclosure

- Coordinacion privada hasta que haya fix disponible o se cumplan 90
  dias desde el reporte (lo que ocurra primero).
- Credito al reportante en el `CHANGELOG.md` y advisory salvo solicitud
  contraria.

## Documentacion relacionada

- [docs/security.md](docs/security.md): modelo de amenazas y controles.
- [docs/encryption.md](docs/encryption.md): algoritmos y rotacion.
- [docs/runbooks.md](docs/runbooks.md): procedimientos operativos.
