
Name: app-mail-routing
Epoch: 1
Version: 1.4.47
Release: 1%{dist}
Summary: Mail Routing - Core
License: LGPLv3
Group: ClearOS/Libraries
Source: app-mail-routing-%{version}.tar.gz
Buildarch: noarch

%description
The Mail Routing provides on the file route handling for various mail apps.

%package core
Summary: Mail Routing - Core
Requires: app-base-core
Requires: app-network-core
Requires: app-smtp-core
Requires: cyrus-sasl-plain
Requires: php-pear-Net-LMTP
Requires: php-pear-Net-SMTP
Requires: webconfig-php-imap

%description core
The Mail Routing provides on the file route handling for various mail apps.

This package provides the core API and libraries.

%prep
%setup -q
%build

%install
mkdir -p -m 755 %{buildroot}/usr/clearos/apps/mail_routing
cp -r * %{buildroot}/usr/clearos/apps/mail_routing/

install -d -m 0755 %{buildroot}/var/clearos/mail_routing
install -d -m 0755 %{buildroot}/var/clearos/mail_routing/backup
install -d -m 0755 %{buildroot}/var/spool/filter
install -D -m 0755 packaging/mailpostfilter %{buildroot}/usr/sbin/mailpostfilter
install -D -m 0755 packaging/mailprefilter %{buildroot}/usr/sbin/mailprefilter

%post core
logger -p local6.notice -t installer 'app-mail-routing-core - installing'

if [ $1 -eq 1 ]; then
    [ -x /usr/clearos/apps/mail_routing/deploy/install ] && /usr/clearos/apps/mail_routing/deploy/install
fi

[ -x /usr/clearos/apps/mail_routing/deploy/upgrade ] && /usr/clearos/apps/mail_routing/deploy/upgrade

exit 0

%preun core
if [ $1 -eq 0 ]; then
    logger -p local6.notice -t installer 'app-mail-routing-core - uninstalling'
    [ -x /usr/clearos/apps/mail_routing/deploy/uninstall ] && /usr/clearos/apps/mail_routing/deploy/uninstall
fi

exit 0

%files core
%defattr(-,root,root)
%exclude /usr/clearos/apps/mail_routing/packaging
%exclude /usr/clearos/apps/mail_routing/tests
%dir /usr/clearos/apps/mail_routing
%dir /var/clearos/mail_routing
%dir /var/clearos/mail_routing/backup
%dir /var/spool/filter
/usr/clearos/apps/mail_routing/deploy
/usr/clearos/apps/mail_routing/language
/usr/clearos/apps/mail_routing/libraries
/usr/sbin/mailpostfilter
/usr/sbin/mailprefilter
