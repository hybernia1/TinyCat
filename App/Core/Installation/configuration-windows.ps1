# Windows PowerShell 5.1 / .NET Framework; invoked only by Installer, JSON on stdin.
# No secrets in arguments, diagnostics or temporary scripts.
# Platform contracts (Microsoft):
# https://learn.microsoft.com/en-us/windows/win32/fileio/file-security-and-access-rights
# https://learn.microsoft.com/en-us/windows/win32/api/winbase/nf-winbase-movefileexw
# https://learn.microsoft.com/en-us/windows/win32/api/fileapi/ns-fileapi-by_handle_file_information
# https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-getfinalpathnamebyhandlew
# https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-getvolumeinformationbyhandlew
# https://learn.microsoft.com/en-us/dotnet/api/system.io.filestream.-ctor?view=netframework-4.8.1
# https://learn.microsoft.com/en-us/dotnet/api/system.security.accesscontrol.objectsecurity.setaccessruleprotection
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
try {
    Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
using System.Text;
using Microsoft.Win32.SafeHandles;
public static class InstallationNative {
    [StructLayout(LayoutKind.Sequential)]
    public struct Information {
        public uint Attributes;
        public System.Runtime.InteropServices.ComTypes.FILETIME Creation, Access, Write;
        public uint Volume, SizeHigh, SizeLow, Links, IndexHigh, IndexLow;
    }
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    public static extern SafeFileHandle CreateFile(string path, uint access, uint share, IntPtr security, uint creation, uint flags, IntPtr template);
    [DllImport("kernel32.dll", SetLastError=true)]
    public static extern bool GetFileInformationByHandle(SafeFileHandle handle, out Information info);
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    public static extern bool MoveFileEx(string source, string target, uint flags);
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    public static extern bool GetVolumeInformationByHandleW(SafeFileHandle handle, StringBuilder name, uint nameLength, out uint serial, out uint componentLength, out uint flags, StringBuilder format, uint formatLength);
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
    public static extern uint GetFinalPathNameByHandleW(SafeFileHandle handle, StringBuilder path, uint length, uint flags);
    [DllImport("kernel32.dll", CharSet=CharSet.Unicode)]
    public static extern uint GetDriveTypeW(string root);
}
'@
    # Bound the entire protocol, not only the decoded configuration payload.
    $inputText = New-Object Text.StringBuilder
    $buffer = New-Object char[] 4096
    while (($count = [Console]::In.Read($buffer, 0, $buffer.Length)) -gt 0) {
        if ($inputText.Length + $count -gt 1500000) { throw 'Configuration protocol exceeds size limit.' }
        $inputText.Append($buffer, 0, $count) | Out-Null
    }
    $request = $inputText.ToString() | ConvertFrom-Json
    if ($request -isnot [PSCustomObject] -or @($request.PSObject.Properties).Count -ne 4 -or
        $request.operation -isnot [string] -or $request.operation -notin @('check', 'publish', 'verify') -or
        $request.root -isnot [string] -or $request.target -isnot [string] -or $request.content -isnot [string] -or
        $request.root.Length -gt 32768 -or $request.target.Length -gt 32768 -or $request.content.Length -gt 1398104 -or
        ($request.operation -ne 'publish' -and $request.content.Length -ne 0)) { throw 'Invalid configuration protocol.' }
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $sid = $identity.User
    $trusted = @($sid.Value, 'S-1-5-18', 'S-1-5-32-544')
    function Assert-LocalNtfs($handle) {
        $name = New-Object Text.StringBuilder 261
        $format = New-Object Text.StringBuilder 32
        [uint32] $serial = 0; [uint32] $length = 0; [uint32] $flags = 0
        if (![InstallationNative]::GetVolumeInformationByHandleW($handle, $name, $name.Capacity, [ref] $serial, [ref] $length, [ref] $flags, $format, $format.Capacity) -or $format.ToString() -ne 'NTFS') { throw 'Configuration requires verified local NTFS.' }
        $path = New-Object Text.StringBuilder 1024
        $count = [InstallationNative]::GetFinalPathNameByHandleW($handle, $path, $path.Capacity, 1)
        if ($count -eq 0 -or $count -ge $path.Capacity) { throw 'Cannot resolve configuration volume identity.' }
        # VOLUME_NAME_GUID resolves the opened object, not a caller-supplied drive letter.
        $volume = [regex]::Match($path.ToString(), '^\\\\\?\\Volume\{[0-9a-fA-F-]+\}\\').Value
        if (!$volume -or [InstallationNative]::GetDriveTypeW($volume) -notin @(2, 3)) { throw 'Configuration requires a local fixed or removable NTFS volume.' }
        return $volume
    }
    function Assert-SimpleDacl($acl) {
        $raw = [Security.AccessControl.RawSecurityDescriptor]::new($acl.GetSecurityDescriptorBinaryForm(), 0)
        if ($null -eq $raw.DiscretionaryAcl -or $raw.DiscretionaryAcl.Count -eq 0) { throw 'Missing configuration DACL.' }
        foreach ($ace in $raw.DiscretionaryAcl) {
            if ($ace -isnot [Security.AccessControl.CommonAce] -or $ace.IsCallback) { throw 'Unsupported configuration access rule.' }
        }
    }
    function Assert-Directory([string] $path) {
        $item = Get-Item -LiteralPath $path -Force
        if (!$item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Unsafe configuration directory.' }
        $acl = [IO.Directory]::GetAccessControl($path)
        Assert-SimpleDacl $acl
        if ($acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -notin $trusted) { throw 'Untrusted configuration directory owner.' }
        $mutation = [Security.AccessControl.FileSystemRights]::Write -bor [Security.AccessControl.FileSystemRights]::Delete -bor [Security.AccessControl.FileSystemRights]::DeleteSubdirectoriesAndFiles -bor [Security.AccessControl.FileSystemRights]::ChangePermissions -bor [Security.AccessControl.FileSystemRights]::TakeOwnership
        foreach ($rule in $acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])) {
            if (($rule.PropagationFlags -band [Security.AccessControl.PropagationFlags]::InheritOnly) -eq 0 -and $rule.AccessControlType -eq 'Allow' -and $rule.IdentityReference.Value -notin $trusted -and ($rule.FileSystemRights -band $mutation)) { throw 'Configuration directory permits untrusted modification.' }
        }
    }
    function Assert-Private([IO.FileStream] $stream) {
        $acl = $stream.GetAccessControl()
        $fileVolume = Assert-LocalNtfs $stream.SafeFileHandle
        Assert-SimpleDacl $acl
        if (!$acl.AreAccessRulesProtected -or $acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -ne $sid.Value) { throw 'Configuration file owner or inheritance is unsafe.' }
        $current = $false
        $rules = $acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])
        if ($rules.Count -eq 0) { throw 'Configuration DACL is empty.' }
        foreach ($rule in $rules) {
            if ($rule.IsInherited -or $rule.AccessControlType -ne 'Allow' -or $rule.IdentityReference.Value -notin $trusted -or $rule.FileSystemRights -ne [Security.AccessControl.FileSystemRights]::FullControl) { throw 'Configuration DACL is not private.' }
            if ($rule.IdentityReference.Value -eq $sid.Value) { $current = $true }
        }
        if (!$current) { throw 'Configuration DACL excludes the runtime identity.' }
        $info = New-Object InstallationNative+Information
        if (![InstallationNative]::GetFileInformationByHandle($stream.SafeFileHandle, [ref] $info) -or $info.Links -ne 1 -or ($info.Attributes -band 0x400)) { throw 'Unsafe configuration file identity.' }
        return "${fileVolume}:$($info.Volume):$($info.IndexHigh):$($info.IndexLow)"
    }
    function Assert-PrivateDirectory([string] $path) {
        Assert-Directory $path
        $acl = [IO.Directory]::GetAccessControl($path)
        if (!$acl.AreAccessRulesProtected -or $acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -ne $sid.Value) { throw 'Unsafe private installation directory.' }
        $current = $false
        foreach ($rule in $acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])) {
            if ($rule.IsInherited -or $rule.AccessControlType -ne 'Allow' -or $rule.IdentityReference.Value -notin $trusted -or
                $rule.FileSystemRights -ne [Security.AccessControl.FileSystemRights]::FullControl -or
                $rule.PropagationFlags -ne [Security.AccessControl.PropagationFlags]::None) { throw 'Unsafe private installation directory DACL.' }
            if ($rule.IdentityReference.Value -eq $sid.Value) { $current = $true }
        }
        if (!$current) { throw 'Private installation directory excludes runtime identity.' }
    }
    $root = [IO.Path]::GetFullPath([string] $request.root).TrimEnd('\')
    $updates = Join-Path $root 'storage\updates'
    $stage = Join-Path $updates 'installation-private'
    $target = [IO.Path]::GetFullPath([string] $request.target)
    if ($target -ne (Join-Path $root 'config.php') -and !$target.StartsWith($updates + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Invalid configuration target.' }
    # Keep every existing ancestor stable against rename/delete for the whole operation.
    $locks = New-Object 'System.Collections.Generic.List[Microsoft.Win32.SafeHandles.SafeFileHandle]'
    $temporary = $null; $stream = $null; $result = $null
    try {
        $ancestor = $root
        while ($ancestor) {
            if (([IO.File]::GetAttributes($ancestor) -band [IO.FileAttributes]::ReparsePoint)) { throw 'Reparse configuration ancestor.' }
            $handle = [InstallationNative]::CreateFile($ancestor, 0x20000, 3, [IntPtr]::Zero, 3, 0x02000000, [IntPtr]::Zero)
            if ($handle.IsInvalid) { $handle.Dispose(); throw 'Cannot lock configuration ancestor.' }
            $locks.Add($handle)
            $ancestor = [IO.Path]::GetDirectoryName($ancestor)
        }
        Assert-Directory $root
        $rootVolume = Assert-LocalNtfs $locks[0]
        foreach ($directory in @((Join-Path $root 'storage'), $updates)) {
            if (![IO.Directory]::Exists($directory)) { [IO.Directory]::CreateDirectory($directory) | Out-Null }
            Assert-Directory $directory
        }
        if (![IO.Directory]::Exists($stage)) {
            $security = New-Object Security.AccessControl.DirectorySecurity
            $security.SetOwner($sid); $security.SetAccessRuleProtection($true, $false)
            foreach ($value in ($trusted | Select-Object -Unique)) {
                $principal = New-Object Security.Principal.SecurityIdentifier($value)
                $security.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($principal, 'FullControl', 'ContainerInherit, ObjectInherit', 'None', 'Allow')))
            }
            [IO.Directory]::CreateDirectory($stage, $security) | Out-Null
        }
        Assert-PrivateDirectory $stage
        $directory = [IO.Path]::GetDirectoryName($target)
        while ($directory -ne $root) {
            Assert-Directory $directory
            $handle = [InstallationNative]::CreateFile($directory, 0x20000, 3, [IntPtr]::Zero, 3, 0x02000000, [IntPtr]::Zero)
            if ($handle.IsInvalid) { $handle.Dispose(); throw 'Cannot lock configuration directory.' }
            $locks.Add($handle)
            $directory = [IO.Path]::GetDirectoryName($directory)
        }
        $stageLock = [InstallationNative]::CreateFile($stage, 0x20000, 3, [IntPtr]::Zero, 3, 0x02000000, [IntPtr]::Zero)
        if ($stageLock.IsInvalid) { $stageLock.Dispose(); throw 'Cannot lock installation staging.' }
        $locks.Add($stageLock)
        $stageVolume = Assert-LocalNtfs $stageLock
        if ($stageVolume -ne $rootVolume) { throw 'Installation staging must remain on the configuration volume.' }
        if ([IO.Directory]::Exists($target) -or ([IO.File]::Exists($target) -and ([IO.File]::GetAttributes($target) -band [IO.FileAttributes]::ReparsePoint))) { throw 'Unsafe configuration target.' }
        if ($request.operation -eq 'verify') {
            $stream = New-Object IO.FileStream($target, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
            $result = Assert-Private $stream
        } else {
            $security = New-Object Security.AccessControl.FileSecurity
            $security.SetOwner($sid)
            $security.SetAccessRuleProtection($true, $false)
            foreach ($value in ($trusted | Select-Object -Unique)) {
                $principal = New-Object Security.Principal.SecurityIdentifier($value)
                $security.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($principal, 'FullControl', 'Allow')))
            }
            $temporary = Join-Path $stage ('.install-config-' + [Guid]::NewGuid().ToString('N') + '.php')
            # The security descriptor is supplied to creation, before any content exists.
            $stream = New-Object IO.FileStream($temporary, [IO.FileMode]::CreateNew, [Security.AccessControl.FileSystemRights]::FullControl, ([IO.FileShare]::Read -bor [IO.FileShare]::Delete), 4096, [IO.FileOptions]::WriteThrough, $security)
            $before = Assert-Private $stream
            $sourceInfo = New-Object InstallationNative+Information
            $directoryInfo = New-Object InstallationNative+Information
            if (![InstallationNative]::GetFileInformationByHandle($stream.SafeFileHandle, [ref] $sourceInfo) -or ![InstallationNative]::GetFileInformationByHandle($locks[$locks.Count - 1], [ref] $directoryInfo)) { throw 'Cannot verify configuration volume.' }
            $destinationHandle = [InstallationNative]::CreateFile([IO.Path]::GetDirectoryName($target), 0x20000, 3, [IntPtr]::Zero, 3, 0x02000000, [IntPtr]::Zero)
            try {
                if (!$destinationHandle.IsInvalid) {
                    $destinationVolume = Assert-LocalNtfs $destinationHandle
                    if ($destinationVolume -ne $stageVolume) { throw 'Configuration publication must preserve volume identity.' }
                }
                if ($destinationHandle.IsInvalid -or ![InstallationNative]::GetFileInformationByHandle($destinationHandle, [ref] $directoryInfo) -or $sourceInfo.Volume -ne $directoryInfo.Volume) { throw 'Configuration publication must stay on one volume.' }
            } finally { $destinationHandle.Dispose() }
            if ($request.operation -eq 'publish') {
                $bytes = [Convert]::FromBase64String([string] $request.content)
                if ($bytes.Length -gt 1048576) { throw 'Configuration exceeds size limit.' }
                $stream.Write($bytes, 0, $bytes.Length); $stream.Flush($true)
                # No COPY_ALLOWED: same-volume rename keeps this verified security descriptor.
                if (![InstallationNative]::MoveFileEx($temporary, $target, 9)) { throw 'Cannot publish private configuration.' }
                $result = New-Object IO.FileStream($target, [IO.FileMode]::Open, [IO.FileAccess]::Read, ([IO.FileShare]::ReadWrite -bor [IO.FileShare]::Delete))
                try { if ((Assert-Private $result) -ne $before) { throw 'Published configuration identity changed.' } } finally { $result.Dispose() }
            } elseif ($request.operation -ne 'check') { throw 'Unknown configuration operation.' }
        }
    } finally {
        if ($stream) { $stream.Dispose() }
        if ($temporary -and [IO.File]::Exists($temporary)) { [IO.File]::Delete($temporary) }
        foreach ($handle in $locks) { $handle.Dispose() }
    }
    [Console]::Out.Write('OK')
} catch {
    # Deliberately omit paths and request data from errors returned to PHP.
    [Console]::Error.Write('Private Windows configuration verification failed.')
    exit 1
}
