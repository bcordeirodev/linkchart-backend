# 🌐 GitHub Actions - Configuração de Rede

## 📋 Problema Identificado

O workflow falha no teste de ping porque muitos servidores de produção têm políticas restritivas para pacotes ICMP, especialmente vindos de data centers conhecidos como o GitHub Actions.

## 🛡️ Soluções Implementadas

### 1. **Remoção do Teste de Ping**
- ✅ Removido: `ping -c 2 -W 3 ${{ env.DEPLOY_HOST }}`
- ✅ Substituído por: Teste de porta SSH (`nc -zv ${{ env.DEPLOY_HOST }} 22`)
- ✅ Motivo: SSH é mais confiável que ICMP para verificar disponibilidade

### 2. **Melhorias no Retry Logic**
- ✅ Timeouts otimizados (5-15 segundos)
- ✅ Retry exponencial com backoff
- ✅ Verificações múltiplas de conectividade

## 🔗 IPs do GitHub Actions (para Whitelist)

Se você quiser permitir especificamente os IPs do GitHub Actions no firewall:

### 📡 Como obter IPs atualizados:
```bash
curl -s https://api.github.com/meta | jq -r '.actions[]'
```

### 🔧 Ranges aproximados (mudam frequentemente):
```
140.82.112.0/20
142.250.0.0/15
185.199.108.0/22
192.30.252.0/22
```

## ⚙️ Configuração Recomendada no Servidor

### 1. **DigitalOcean Firewall Rules**
```bash
# Permitir SSH de qualquer lugar (cuidado com segurança)
ufw allow 22/tcp

# Ou permitir apenas GitHub Actions (ranges específicos)
ufw allow from 140.82.112.0/20 to any port 22
ufw allow from 185.199.108.0/22 to any port 22
```

### 2. **Fail2Ban Configuration**
```bash
# /etc/fail2ban/jail.local
[sshd]
enabled = true
port = ssh
filter = sshd
logpath = /var/log/auth.log
maxretry = 10
bantime = 300
findtime = 600

# Whitelist GitHub Actions IPs
ignoreip = 127.0.0.1/8 140.82.112.0/20 185.199.108.0/22
```

### 3. **SSH Rate Limiting (sshd_config)**
```bash
# /etc/ssh/sshd_config
MaxStartups 30:30:100
MaxSessions 20
ClientAliveInterval 300
ClientAliveCountMax 2
```

## 🚨 Troubleshooting

### Se o workflow ainda falhar:

1. **Verificar conectividade manual:**
```bash
ssh root@138.197.121.81 "echo 'SSH OK'"
```

2. **Verificar logs do servidor:**
```bash
ssh root@138.197.121.81 "journalctl -u ssh -n 20"
```

3. **Verificar fail2ban:**
```bash
ssh root@138.197.121.81 "fail2ban-client status sshd"
```

4. **Testar diferentes GitHub Actions runners:**
```yaml
# No workflow, adicionar:
strategy:
  matrix:
    runner: [ubuntu-latest, ubuntu-20.04]
```

## 📊 Monitoramento

### Verificar status em tempo real:
```bash
# No servidor
watch -n 5 'netstat -tn | grep :22'
watch -n 5 'fail2ban-client status sshd'
```

## 🔄 Workflow Atualizado

O workflow agora:
- ✅ **Não depende** de ping
- ✅ **Testa porta SSH** com netcat
- ✅ **Retry robusto** com backoff exponencial
- ✅ **Timeouts otimizados**
- ✅ **Logs informativos** sobre causas de falha

## 💡 Dicas Adicionais

1. **ICMP pode estar desabilitado** por segurança
2. **SSH funciona** mesmo quando ping falha
3. **Rate limiting** é comum em servidores de produção
4. **Usar chaves SSH** ao invés de senhas
5. **Monitorar logs** para identificar bloqueios

---

*Este documento foi gerado após análise do workflow que falhava no teste de ping.*
